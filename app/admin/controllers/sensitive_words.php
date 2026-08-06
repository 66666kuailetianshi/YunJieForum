<?php
/**
 * 云界论坛 - 管理后台敏感词管理
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';
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

// CSRF 统一校验：涉及状态变更的 GET 操作必须先通过校验，失败时明确提示（避免静默失败）
if (in_array($action, ['delete_word', 'delete_whitelist'], true) && !validate_csrf()) {
    set_flash(t('admin_sensitive_csrf_failed', '安全校验失败（链接已过期），请刷新页面后重新操作。'), 'error');
    redirect('/admin/sensitive_words');
}

// 删除敏感词
if ($action === 'delete_word' && isset($_GET['id'])) {
    $db->prepare("DELETE FROM sensitive_words WHERE id = :id")->execute([':id' => (int)$_GET['id']]);
    clear_sensitive_filter_cache();
    set_flash(t('admin_sensitive_flash_deleted', '敏感词已删除。'), 'success');
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
if ($action === 'delete_whitelist' && isset($_GET['id'])) {
    $db->prepare("DELETE FROM sensitive_word_whitelist WHERE id = :id")->execute([':id' => (int)$_GET['id']]);
    clear_sensitive_filter_cache();
    set_flash(t('admin_sensitive_flash_white_deleted', '白名单已删除。'), 'success');
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

<style>
/* ===== 统计卡片 ===== */
.sw-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem; }
.sw-stat-card { background: var(--surface); border: 1px solid var(--border-soft); border-radius: var(--radius-lg); padding: 1rem 1.125rem; position: relative; overflow: hidden; transition: var(--transition); }
.sw-stat-card:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }
.sw-stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; border-radius: var(--radius-lg) var(--radius-lg) 0 0; }
.sw-stat-card.sc-total::before { background: var(--primary); }
.sw-stat-card.sc-enabled::before { background: var(--success); }
.sw-stat-card.sc-intercept::before { background: var(--error); }
.sw-stat-card.sc-whitelist::before { background: var(--info, #0ea5e9); }
.sw-stat-card.sc-logs-today::before { background: var(--warning); }
.sw-stat-card.sc-logs-total::before { background: var(--secondary); }
.sw-stat-label { font-size: var(--text-xs); color: var(--text-3); font-weight: 500; text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 0.25rem; }
.sw-stat-value { font-size: 1.5rem; font-weight: 700; line-height: 1.1; color: var(--text); }
.sw-stat-sub { font-size: var(--text-xs); color: var(--text-4); margin-top: 0.125rem; }

/* ===== 分类分布条 ===== */
.sw-cat-bar-wrap { margin-bottom: 1.25rem; }
.sw-cat-bar { display: flex; height: 28px; border-radius: var(--radius-full); overflow: hidden; background: var(--surface-2); border: 1px solid var(--border-soft); }
.sw-cat-bar-seg { display: flex; align-items: center; justify-content: center; font-size: var(--text-xs); font-weight: 600; color: #fff; transition: var(--transition); cursor: default; white-space: nowrap; overflow: hidden; }
.sw-cat-bar-seg:hover { filter: brightness(1.1); }
.sw-cat-legend { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem; }
.sw-cat-legend-item { display: inline-flex; align-items: center; gap: 0.25rem; font-size: var(--text-xs); color: var(--text-3); }
.sw-cat-legend-dot { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }

/* ===== 搜索 + 分类/等级筛选工具栏 ===== */
.sw-toolbar { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; }
.sw-toolbar .admin-search-form { flex: 1; min-width: 200px; display: flex; gap: 0.5rem; }
.sw-toolbar .admin-search-form .form-control { flex: 1; }

.sw-filter-row { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; margin-top: 0.75rem; }
.sw-filter-group { display: inline-flex; align-items: center; gap: 0.375rem; background: var(--surface-2); border: 1px solid var(--border-soft); border-radius: var(--radius-full); padding: 0.25rem 0.25rem 0.25rem 0.75rem; }
.sw-filter-group label { font-size: var(--text-xs); color: var(--text-3); font-weight: 500; white-space: nowrap; }
.sw-filter-group .form-control {
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    width: auto;
    min-width: 120px;
    background-color: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-full);
    padding: 0.25rem 1.25rem 0.25rem 0.65rem;
    font-size: var(--text-xs);
    font-weight: 500;
    color: var(--text);
    background-image: url("data:image/svg+xml;charset=utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='8' height='8' viewBox='0 0 24 24' fill='none' stroke='%23a1a1aa' stroke-width='3' stroke-linecap='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 0.4rem center;
    cursor: pointer;
}

/* ===== 列表标题区 meta ===== */
.sw-list-meta { font-size: var(--text-sm); color: var(--text-3); }
.sw-list-meta strong { color: var(--text); font-weight: 600; }

/* ===== 紧凑表格（重做） ===== */
.sw-table-wrap { border: 1px solid var(--border-soft); border-radius: var(--radius-lg); background: var(--surface); overflow-x: auto; -webkit-overflow-scrolling: touch; }
.sw-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: var(--text-sm); }
.sw-table thead th {
    background: var(--surface-2);
    color: var(--text-3);
    font-weight: 600;
    text-align: left;
    padding: 0.6rem 0.875rem;
    border-bottom: 1px solid var(--border);
    font-size: var(--text-xs);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    white-space: nowrap;
    position: sticky;
    top: 0;
    z-index: 1;
}
.sw-table th.sortable { cursor: pointer; user-select: none; transition: color 0.15s; }
.sw-table th.sortable:hover { color: var(--primary); }
.sw-table th.sortable .sort-ind { margin-left: 4px; opacity: 0.4; font-size: 10px; }
.sw-table th.sortable.active { color: var(--primary); }
.sw-table th.sortable.active .sort-ind { opacity: 1; color: var(--primary); }

.sw-table tbody tr { transition: background 0.15s, opacity 0.2s, box-shadow 0.15s; position: relative; }
.sw-table tbody tr:hover { background: var(--surface-2); }
/* 选中态：左侧蓝色边框 + 浅蓝背景 */
.sw-table tbody tr.checked { background: var(--primary-lighter, rgba(99, 102, 241, 0.06)); box-shadow: inset 3px 0 0 var(--primary); }
.sw-table tbody tr.checked:hover { background: var(--primary-light, rgba(99, 102, 241, 0.1)); }
/* 禁用态：更柔和（删除线 + 半透明） */
.sw-table tbody tr.disabled { opacity: 0.5; }
.sw-table tbody tr.disabled .sw-cell-word-text { text-decoration: line-through; text-decoration-color: var(--text-4); }
.sw-table tbody tr.disabled:hover { opacity: 0.75; }

.sw-table td { padding: 0.65rem 0.875rem; vertical-align: middle; border-bottom: 1px solid var(--border-soft); line-height: 1.5; }
.sw-table tbody tr:last-child td { border-bottom: none; }

.sw-cell-word { font-weight: 500; max-width: 360px; display: flex; align-items: center; gap: 0.5rem; min-width: 120px; }
.sw-cell-word-text { min-width: 0; word-break: keep-all; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* 匹配模式徽章（贴在词旁） */
.sw-mode-badge { display: inline-flex; align-items: center; padding: 0.1rem 0.4rem; border-radius: var(--radius-xs); background: var(--surface-3); color: var(--text-4); font-size: 10px; font-weight: 600; line-height: 1.4; flex-shrink: 0; letter-spacing: 0.02em; }
.sw-mode-badge.mode-word { background: var(--primary-light); color: var(--primary-dark); }
.sw-mode-badge.mode-regex { background: var(--warning-light); color: var(--warning); }

/* 表格行操作按钮默认隐藏、悬停显示 */
.sw-table tbody tr .sw-cell-actions { opacity: 0.5; transition: opacity 0.15s; }
.sw-table tbody tr:hover .sw-cell-actions,
.sw-table tbody tr.selected .sw-cell-actions { opacity: 1; }

/* 分类 chip 风格 select（带色点） */
.sw-cat-wrap {
    position: relative;
    display: inline-flex;
    align-items: center;
    max-width: 100%;
}
.sw-cat-dot {
    position: absolute;
    left: 0.55rem;
    top: 50%;
    transform: translateY(-50%);
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--cat-color, var(--text-4));
    pointer-events: none;
    z-index: 1;
}
.sw-cat-select {
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    background-color: transparent;
    border: 1px solid transparent;
    color: var(--text-2);
    padding: 0.25rem 1.2rem 0.25rem 1.5rem;
    border-radius: var(--radius-full);
    font-size: var(--text-xs);
    font-weight: 500;
    cursor: pointer;
    background-image: url("data:image/svg+xml;charset=utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='8' height='8' viewBox='0 0 24 24' fill='none' stroke='%23a1a1aa' stroke-width='3' stroke-linecap='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 0.35rem center;
    transition: var(--transition);
    max-width: 100%;
}
.sw-cat-select:hover { background-color: var(--surface-2); border-color: var(--border-soft); }
.sw-cat-select:focus { border-color: var(--primary); outline: none; background-color: var(--surface-2); }

/* 等级药丸（点击循环） */
.sw-level-pill {
    display: inline-flex; align-items: center; gap: 0.35rem;
    padding: 0.25rem 0.6rem;
    border-radius: var(--radius-full);
    font-size: var(--text-xs); font-weight: 600;
    cursor: pointer; user-select: none;
    border: 1px solid transparent;
    transition: var(--transition);
    white-space: nowrap;
}
.sw-level-pill::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; flex-shrink: 0; }
.sw-level-pill.lv-1 { color: var(--secondary); background: var(--surface-2); }
.sw-level-pill.lv-2 { color: var(--error); background: var(--error-light); }
.sw-level-pill.lv-3 { color: var(--warning); background: var(--warning-light); }
.sw-level-pill:hover { filter: brightness(0.95); transform: scale(1.04); }
.sw-level-pill:active { transform: scale(0.96); }

/* 开关 */
.sw-switch { position: relative; display: inline-block; width: 34px; height: 19px; flex-shrink: 0; vertical-align: middle; }
.sw-switch input { opacity: 0; width: 0; height: 0; }
.sw-switch-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background: var(--surface-3); border-radius: var(--radius-full); transition: 0.2s; }
.sw-switch-slider::before { content: ''; position: absolute; height: 15px; width: 15px; left: 2px; bottom: 2px; background: #fff; border-radius: 50%; transition: 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.3); }
.sw-switch input:checked + .sw-switch-slider { background: var(--success); }
.sw-switch input:checked + .sw-switch-slider::before { transform: translateX(15px); }
.sw-switch input:focus-visible + .sw-switch-slider { box-shadow: 0 0 0 3px var(--brand-glow); }

/* 图标操作按钮 - 行悬停时淡入 */
.sw-cell-actions { white-space: nowrap; text-align: right; }
.sw-icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: var(--radius-sm); background: transparent; border: none; color: var(--text-4); cursor: pointer; transition: var(--transition); text-decoration: none; padding: 0; vertical-align: middle; }
.sw-icon-btn svg { width: 15px; height: 15px; pointer-events: none; }
.sw-icon-btn:hover { background: var(--surface-3); }
.sw-icon-btn.btn-edit:hover { color: var(--primary); background: var(--primary-light); }
.sw-icon-btn.btn-delete:hover { color: var(--error); background: var(--error-light); }
.sw-icon-btn:focus-visible { outline: 2px solid var(--primary); outline-offset: 1px; }
/* 删除按钮武装态（待确认） */
.sw-icon-btn.armed { opacity: 1 !important; color: #fff; background: var(--error); animation: sw-arm-pulse 1s ease-in-out infinite; }
.sw-icon-btn.armed:hover { background: var(--error); filter: brightness(1.05); }
@keyframes sw-arm-pulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); } 50% { box-shadow: 0 0 0 5px rgba(239, 68, 68, 0); } }

/* 行高亮动画 */
.sw-row.flash { animation: sw-flash 1s ease; }
@keyframes sw-flash {
    0% { background: var(--primary-light); }
    100% { background: transparent; }
}
.sw-row.deleting { opacity: 0.3; pointer-events: none; transition: opacity 0.2s; }

/* 内嵌批量操作栏（表格底部） */
.sw-batch-bar { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; margin-top: 0; padding: 0.75rem 0.875rem; border-top: 1px solid var(--border-soft); background: var(--surface-2); border-radius: 0 0 var(--radius-lg) var(--radius-lg); }
.sw-batch-bar .sw-batch-info { font-size: var(--text-xs); color: var(--text-3); }
.sw-batch-bar .sw-batch-info strong { color: var(--primary); font-weight: 600; }
.sw-batch-bar .form-control { width: auto !important; min-width: 120px; }

/* 浮动批量操作栏（选中后底部滑出） */
.sw-floating-bar {
    position: fixed; bottom: 1.5rem; left: 50%; transform: translateX(-50%) translateY(120%);
    display: flex; align-items: center; gap: 0.625rem;
    padding: 0.625rem 0.875rem;
    background: var(--text); color: #fff;
    border-radius: var(--radius-full);
    box-shadow: 0 8px 24px rgba(0,0,0,0.25), 0 2px 8px rgba(0,0,0,0.15);
    z-index: 50;
    transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.2s;
    opacity: 0; pointer-events: none;
    max-width: calc(100vw - 2rem);
}
.sw-floating-bar.visible { transform: translateX(-50%) translateY(0); opacity: 1; pointer-events: auto; }
.sw-floating-bar .sw-fb-count { font-size: var(--text-sm); font-weight: 600; padding: 0 0.25rem; }
.sw-floating-bar .sw-fb-count strong { color: #fff; }
.sw-floating-bar .sw-fb-divider { width: 1px; height: 16px; background: rgba(255,255,255,0.2); }
.sw-floating-bar .sw-fb-btn {
    padding: 0.25rem 0.75rem; border-radius: var(--radius-full);
    background: rgba(255,255,255,0.1); border: none; color: #fff;
    font-size: var(--text-xs); font-weight: 500; cursor: pointer;
    transition: var(--transition); white-space: nowrap;
}
.sw-floating-bar .sw-fb-btn:hover { background: rgba(255,255,255,0.2); }
.sw-floating-bar .sw-fb-btn.danger:hover { background: var(--error); }
.sw-floating-bar .sw-fb-close {
    display: inline-flex; align-items: center; justify-content: center;
    width: 22px; height: 22px; border-radius: 50%;
    background: transparent; border: none; color: rgba(255,255,255,0.6);
    cursor: pointer; transition: var(--transition); margin-left: 0.25rem;
}
.sw-floating-bar .sw-fb-close:hover { background: rgba(255,255,255,0.15); color: #fff; }

.sw-empty-row td { text-align: center; padding: 3rem 1rem; color: var(--text-3); }
.sw-empty-row .sw-empty-icon { width: 48px; height: 48px; margin: 0 auto 0.75rem; color: var(--text-4); opacity: 0.5; }
.sw-empty-row .sw-empty-text { font-size: var(--text-sm); color: var(--text-3); margin-bottom: 0.25rem; }
.sw-empty-row .sw-empty-hint { font-size: var(--text-xs); color: var(--text-4); }

/* ===== 白名单标签云 ===== */
.swl-cloud { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.swl-chip { display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.3rem 0.5rem 0.3rem 0.75rem; background: var(--surface-2); border: 1px solid var(--border-soft); border-radius: var(--radius-full); font-size: var(--text-sm); transition: var(--transition); }
.swl-chip:hover { border-color: var(--primary); background: var(--primary-light); }
.swl-chip.disabled { opacity: 0.45; text-decoration: line-through; }
.swl-chip-del { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 50%; background: transparent; border: none; color: var(--text-4); cursor: pointer; font-size: 14px; line-height: 1; transition: var(--transition); text-decoration: none; }
.swl-chip-del:hover { background: var(--error); color: #fff; }

/* ===== 折叠面板 ===== */
.sw-collapsible { overflow: hidden; transition: max-height 0.3s ease; }
.sw-collapsible.collapsed { max-height: 0 !important; }
.sw-collapse-toggle { cursor: pointer; user-select: none; display: inline-flex; align-items: center; gap: 0.375rem; }
.sw-collapse-toggle .chevron { transition: transform 0.2s ease; }
.sw-collapse-toggle.collapsed .chevron { transform: rotate(-90deg); }

/* ===== A+D 双栏主从布局 ===== */
.sw-master-detail { display: grid; grid-template-columns: minmax(0, 1.55fr) minmax(0, 1fr); gap: 0.75rem; align-items: start; }
.sw-master { min-width: 0; }
.sw-detail { position: sticky; top: calc(var(--header-height, 60px) + 1rem); min-width: 0; }
.sw-detail-card { background: var(--surface); border: 1px solid var(--border-soft); border-radius: var(--radius-lg); overflow: hidden; }
.sw-detail-header { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; padding: 0.75rem 1rem; border-bottom: 1px solid var(--border-soft); background: var(--surface-2); }
.sw-detail-header h3 { margin: 0; font-size: var(--text-base); font-weight: 600; color: var(--text); display: flex; align-items: center; gap: 0.5rem; }
.sw-detail-header .sw-detail-tag { font-size: var(--text-xs); color: var(--text-3); background: var(--surface-3); padding: 0.1rem 0.5rem; border-radius: var(--radius-full); font-weight: 500; }
.sw-detail-close { display: none; }
.sw-detail-body { padding: 1rem; }
.sw-detail-body .form-group { margin-bottom: 0.875rem; }
.sw-detail-body .form-label { margin-bottom: 0.3rem; font-size: var(--text-sm); }
.sw-detail-body .form-control { padding: 0.5rem 0.75rem; }
.sw-detail-body .btn { padding: 0.5rem 1rem; font-size: var(--text-sm); }
.sw-detail-empty { padding: 3rem 1.5rem; text-align: center; }
.sw-detail-empty .sw-empty-icon { width: 56px; height: 56px; margin: 0 auto 1rem; color: var(--text-4); opacity: 0.35; }
.sw-detail-empty .sw-empty-title { font-size: var(--text-sm); color: var(--text-2); margin-bottom: 0.25rem; font-weight: 500; }
.sw-detail-empty .sw-empty-hint { font-size: var(--text-xs); color: var(--text-4); }

/* 列表行选中态（主从模式） */
.sw-table tbody tr.selected { background: var(--primary-lighter, rgba(99,102,241,0.08)) !important; box-shadow: inset 3px 0 0 var(--primary); }
.sw-table tbody tr.selected:hover { background: var(--primary-light, rgba(99,102,241,0.12)) !important; }
.sw-table tbody tr { cursor: pointer; }
/* 点击行进入编辑，禁用控件点击穿透 */
.sw-table tbody tr .sw-cat-select,
.sw-table tbody tr .sw-level-pill,
.sw-table tbody tr .sw-switch,
.sw-table tbody tr .sw-icon-btn,
.sw-table tbody tr .sw-check { cursor: pointer; position: relative; z-index: 1; }
.sw-table tbody tr .sw-cell-word-text { pointer-events: none; }

/* 移动端：详情面板变抽屉 */
.sw-detail-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 90; opacity: 0; transition: opacity 0.2s; }

@media (max-width: 920px) {
    .sw-master-detail { grid-template-columns: 1fr; }
    .sw-detail { position: fixed; top: 0; right: 0; bottom: 0; width: min(420px, 100vw); z-index: 100; transform: translateX(100%); transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1); display: flex; flex-direction: column; }
    .sw-detail.visible { transform: translateX(0); }
    .sw-detail-backdrop.visible { display: block; opacity: 1; }
    .sw-detail-card { flex: 1; margin: 0; border-radius: 0; border: none; display: flex; flex-direction: column; }
    .sw-detail-header { border-radius: 0; flex-shrink: 0; }
    .sw-detail-body { flex: 1; overflow-y: auto; -webkit-overflow-scrolling: touch; }
    .sw-detail-close { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: var(--radius-sm); background: transparent; border: none; color: var(--text-3); cursor: pointer; transition: var(--transition); }
    .sw-detail-close:hover { background: var(--surface-3); color: var(--text); }
    .sw-detail-close svg { width: 16px; height: 16px; }
}

/* 移动端表格优化 */
@media (max-width: 767px) {
    .sw-stats-grid { grid-template-columns: repeat(2, 1fr); gap: 0.5rem; }
    .sw-stat-card { padding: 0.75rem; }
    .sw-stat-value { font-size: 1.25rem; }
    .sw-cat-legend { gap: 0.375rem; }
    .sw-cat-legend-item { font-size: 11px; }
    .sw-toolbar { flex-direction: column; align-items: stretch; }
    .sw-toolbar .admin-search-form { flex-direction: column; }
    .sw-table { font-size: 13px; }
    .sw-table td, .sw-table th { padding: 0.4rem 0.5rem; }
    .sw-cell-word { max-width: 100%; }
    .sw-floating-bar { left: 0.5rem; right: 0.5rem; transform: translateY(120%); max-width: none; border-radius: var(--radius-lg); }
    .sw-floating-bar.visible { transform: translateY(0); }
    .sw-batch-bar { flex-direction: column; align-items: stretch; gap: 0.5rem; }
    .sw-batch-bar .form-control { width: 100% !important; }
}
@media (max-width: 480px) {
    .sw-stats-grid { grid-template-columns: 1fr; }
    .sw-table { min-width: 480px; }
    .sw-floating-bar { flex-wrap: wrap; justify-content: center; border-radius: var(--radius-lg); }
}
</style>

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
                    <label class="flex items-center gap-1" style="cursor:pointer;">
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
                    <form method="POST" action="<?php echo site_url('admin/sensitive_words', ['action' => 'words']); ?>" style="margin-top:0.75rem;">
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
                    <a href="<?php echo site_url('admin/sensitive_words', ['action' => 'delete_whitelist', 'id' => (int)$w['id'], 'csrf_token' => csrf_token()]); ?>" class="swl-chip-del" data-confirm="<?php echo e(t('admin_sensitive_confirm_delete', '确定删除吗？')); ?>">&times;</a>
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
