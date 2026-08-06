<?php
/**
 * 云界论坛管理后台 - 协议页面管理
 * 编辑用户协议、隐私政策等公开页面的 HTML 内容
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

$pageTitle = t('admin_pages_title', '协议页面管理');
$activeMenu = 'site_pages';
$successMsg = '';
$errorMsg   = '';

// 获取所有页面
$pages = get_all_site_pages();

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_page'])) {
    $slug    = trim($_POST['slug'] ?? '');
    $title   = trim($_POST['title'] ?? '');
    $content = $_POST['content'] ?? '';

    if ($slug === '' || $title === '') {
        $errorMsg = t('admin_pages_error_required', '页面标识和标题不能为空。');
    } else {
        update_site_page($slug, $title, $content);
        $successMsg = t('admin_pages_saved', '页面 "{title}" 已保存，前台页面已同步更新。', ['title' => e($title)]);
        $pages = get_all_site_pages();
    }
}

// 处理恢复默认
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_default'])) {
    $slug = trim($_POST['slug'] ?? '');
    if ($slug !== '') {
        // 从 init_default_site_pages 逻辑重建默认内容
        $db = get_db();
        $stmt = $db->prepare("DELETE FROM site_pages WHERE slug = :slug");
        $stmt->execute([':slug' => $slug]);
        init_default_site_pages($db);
        $pages = get_all_site_pages();
        $successMsg = t('admin_pages_reset_done', '已恢复为默认内容。');
    }
}

// 当前编辑的页面
$editingSlug = $_GET['page'] ?? ($pages[0]['slug'] ?? '');
$editingPage = null;
foreach ($pages as $p) {
    if ($p['slug'] === $editingSlug) {
        $editingPage = $p;
        break;
    }
}
if (!$editingPage && count($pages) > 0) {
    $editingPage = $pages[0];
    $editingSlug = $editingPage['slug'];
}

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?php echo e(t('admin_pages_title', '协议页面管理')); ?></h1>
        <p class="page-subtitle"><?php echo e(t('admin_pages_subtitle', '编辑用户协议、隐私政策等公开页面内容，修改后立即在网站前台生效')); ?></p>
    </div>
</div>

<?php if ($successMsg): ?>
    <div class="alert alert-success"><?php echo e($successMsg); ?></div>
<?php endif; ?>
<?php if ($errorMsg): ?>
    <div class="alert alert-error"><?php echo e($errorMsg); ?></div>
<?php endif; ?>

<?php if (empty($pages)): ?>
    <div class="card" style="text-align:center;padding:2rem;">
        <p style="color:var(--muted);margin-bottom:1rem;"><?php echo e(t('admin_pages_empty', '暂无可编辑页面。')); ?></p>
    </div>
<?php else: ?>
    <!-- Tab 切换 -->
    <div class="card" style="margin-bottom:1rem;">
        <div style="display:flex;gap:0;padding:0.5rem 0.5rem 0 0.5rem;overflow-x:auto;">
            <?php foreach ($pages as $p): ?>
                <a href="<?php echo site_url('admin/site_pages', ['page' => $p['slug']]); ?>"
                   class="site-page-tab <?php echo $p['slug'] === $editingSlug ? 'site-page-tab-active' : ''; ?>"
                   style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.6rem 1.2rem;border-radius:8px 8px 0 0;text-decoration:none;font-weight:<?php echo $p['slug'] === $editingSlug ? '600' : '400'; ?>;font-size:0.9rem;
                          color:<?php echo $p['slug'] === $editingSlug ? 'var(--primary)' : 'var(--muted)'; ?>;
                          background:<?php echo $p['slug'] === $editingSlug ? 'var(--surface)' : 'transparent'; ?>;
                          border:1px solid <?php echo $p['slug'] === $editingSlug ? 'var(--border)' : 'transparent'; ?>;
                          border-bottom:<?php echo $p['slug'] === $editingSlug ? '1px solid var(--surface)' : 'none'; ?>;
                          transition:all 0.15s ease;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <?php echo e($p['title']); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($editingPage): ?>
        <!-- 编辑器 + 预览 双栏 -->
        <div style="display:flex;gap:1.5rem;align-items:flex-start;">
            <!-- 左侧：编辑表单 -->
            <div class="card" style="flex:1;min-width:0;">
                <div style="display:flex;justify-content:space-between;align-items:center;padding:1rem 1.5rem;border-bottom:1px solid var(--border);">
                    <div>
                        <span style="font-weight:600;"><?php echo e(t('admin_pages_editing_prefix', '编辑：')); ?><?php echo e($editingPage['title']); ?></span>
                    </div>
                    <div style="display:flex;align-items:center;gap:0.75rem;">
                        <span style="font-size:0.8rem;color:var(--muted);">
                            <?php echo e(t('admin_pages_last_updated_prefix', '最后更新：')); ?><?php echo e($editingPage['updated_at'] ?? t('admin_pages_never', '从未')); ?>
                        </span>
                        <span style="font-size:0.8rem;color:var(--muted);" id="content-stats">
                            <?php echo e(t('admin_pages_char_count_prefix', '字符数：')); ?>0
                        </span>
                    </div>
                </div>

                <form method="POST" id="page-editor-form" style="padding:1.5rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="save_page" value="1">
                    <input type="hidden" name="slug" value="<?php echo e($editingPage['slug']); ?>">

                    <div class="form-group">
                        <label for="title" class="form-label"><?php echo e(t('admin_pages_label_title', '页面标题')); ?></label>
                        <input type="text" id="title" name="title" class="form-control"
                               value="<?php echo e($editingPage['title']); ?>" required>
                    </div>

                    <!-- HTML 快捷插入工具栏 -->
                    <div class="form-group" style="margin-bottom:0.5rem;">
                        <label class="form-label"><?php echo e(t('admin_pages_label_quick_insert', '快捷插入')); ?></label>
                        <div style="display:flex;flex-wrap:wrap;gap:0.4rem;">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="insertTag('h2')" title="<?php echo e(t('admin_pages_tool_h2_title', '章节标题\"')); ?>><?php echo e(t('admin_pages_tool_h2', 'H2 标题')); ?></button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="insertTag('p')" title="<?php echo e(t('admin_pages_tool_p_title', '段落\"')); ?>><?php echo e(t('admin_pages_tool_p', '段落')); ?></button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="insertWrap('strong')" title="<?php echo e(t('admin_pages_tool_bold_title', '加粗\"')); ?>><?php echo e(t('admin_pages_tool_bold', '加粗')); ?></button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="insertList('ol')" title="<?php echo e(t('admin_pages_tool_ol_title', '有序列表\"')); ?>><?php echo e(t('admin_pages_tool_ol', '有序列表')); ?></button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="insertList('ul')" title="<?php echo e(t('admin_pages_tool_ul_title', '无序列表\"')); ?>><?php echo e(t('admin_pages_tool_ul', '无序列表')); ?></button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="insertBr()" title="<?php echo e(t('admin_pages_tool_br_title', '换行\"')); ?>><?php echo e(t('admin_pages_tool_br', '换行')); ?></button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="content" class="form-label"><?php echo e(t('admin_pages_label_content', '页面内容（HTML）')); ?></label>
                        <textarea id="content" name="content" class="form-control" rows="22"
                            style="font-family:'Consolas','Monaco','Menlo',monospace;font-size:0.85rem;line-height:1.7;tab-size:2;min-height:420px;"
                            oninput="updatePreview();updateStats();"
                            onkeydown="handleTab(event)"
                        ><?php echo e($editingPage['content']); ?></textarea>
                    </div>

                    <div style="display:flex;gap:0.75rem;margin-top:1rem;flex-wrap:wrap;">
                        <button type="submit" class="btn btn-primary"><?php echo e(t('admin_pages_save_publish', '保存并发布')); ?></button>
                        <a href="<?php echo e('/' . ($editingPage['slug'] === 'privacy' ? 'privacy' : ($editingPage['slug'] === 'disclaimer' ? 'disclaimer' : ($editingPage['slug'] === 'service' ? 'service' : 'terms')))); ?>"
                           target="_blank" class="btn btn-secondary" rel="noopener"><?php echo e(t('admin_pages_frontend_preview', '前台预览')); ?></a>
                    </div>
                </form>

                <!-- 恢复默认 — 独立表单（不可嵌套在编辑表单内） -->
                <form method="POST" style="padding:0 1.5rem 1.5rem;"
                      onsubmit=t('admin_site_pages_59c4eb','return confirm(\'<?php echo e(t(\'admin_pages_confirm_reset\', \'确认恢复为默认内容？当前编辑的内容将被覆盖。\')); ?>\');')>
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="reset_default" value="1">
                    <input type="hidden" name="slug" value="<?php echo e($editingPage['slug']); ?>">
                    <button type="submit" class="btn btn-sm" style="background:transparent;border:1px solid var(--border);color:var(--muted);"><?php echo e(t('admin_pages_reset_default', '恢复默认')); ?></button>
                </form>
            </div>

            <!-- 右侧：实时预览 -->
            <div class="card" style="flex:1;min-width:0;position:sticky;top:4.5rem;">
                <div style="padding:0.75rem 1.5rem;border-bottom:1px solid var(--border);font-weight:600;font-size:0.9rem;display:flex;justify-content:space-between;align-items:center;">
                    <span><?php echo e(t('admin_pages_live_preview', '实时预览')); ?></span>
                    <span style="font-size:0.75rem;color:var(--muted);"><?php echo e(t('admin_pages_preview_hint', '桌面 / 移动端效果')); ?></span>
                </div>
                <div id="preview-pane" style="padding:1.5rem;line-height:1.85;max-height:70vh;overflow-y:auto;font-size:0.95rem;"
                     class="terms-content">
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<style>
/* Tab 激活样式 */
.site-page-tab:hover {
    color: var(--primary) !important;
    background: var(--surface-hover) !important;
}

/* 预览面板样式 */
#preview-pane h2 {
    font-size: 1.15rem;
    font-weight: 600;
    margin-top: 1.5rem;
    margin-bottom: 0.6rem;
    color: var(--text);
    border-bottom: 1px solid var(--border-light, #e5e7eb);
    padding-bottom: 0.35rem;
}
#preview-pane ol,
#preview-pane ul {
    padding-left: 1.5rem;
    margin-bottom: 0.8rem;
}
#preview-pane ol ul,
#preview-pane ul ul {
    margin-top: 0.3rem;
    margin-bottom: 0.3rem;
}
#preview-pane li {
    margin-bottom: 0.35rem;
}
#preview-pane p {
    margin-bottom: 0.8rem;
}
#preview-pane strong {
    font-weight: 600;
    color: var(--text);
}

@media (max-width: 1024px) {
    #preview-pane {
        display: none;
    }
}
</style>

<script>
(function () {
    var ta = document.getElementById('content');
    // 初始化预览和统计
    if (ta) {
        updatePreview();
        updateStats();
    }

    // Ctrl+S 快捷键保存
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            document.getElementById('page-editor-form').submit();
        }
    });
})();

function handleTab(e) {
    if (e.key === 'Tab') {
        e.preventDefault();
        var ta = e.target;
        var start = ta.selectionStart;
        var end = ta.selectionEnd;
        ta.value = ta.value.substring(0, start) + '  ' + ta.value.substring(end);
        ta.selectionStart = ta.selectionEnd = start + 2;
        updatePreview();
        updateStats();
    }
}

function insertTag(tag) {
    var ta = document.getElementById('content');
    var start = ta.selectionStart;
    var end = ta.selectionEnd;
    var text = ta.value.substring(start, end);
    if (!text) text = (tag === 'h2' ? <?php echo json_encode(t('admin_pages_js_section_title', '章节标题')); ?> : <?php echo json_encode(t('admin_pages_js_paragraph_content', '段落内容')); ?>);
    var replacement = '<' + tag + '>' + text + '</' + tag + '>';
    ta.setRangeText(replacement, start, end, 'select');
    ta.focus();
    updatePreview();
    updateStats();
}

function insertWrap(tag) {
    var ta = document.getElementById('content');
    var start = ta.selectionStart;
    var end = ta.selectionEnd;
    var text = ta.value.substring(start, end);
    if (!text) text = <?php echo json_encode(t('admin_pages_js_bold_text', '加粗文字')); ?>;
    ta.setRangeText('<' + tag + '>' + text + '</' + tag + '>', start, end, 'end');
    ta.focus();
    updatePreview();
    updateStats();
}

function insertList(type) {
    var ta = document.getElementById('content');
    var start = ta.selectionStart;
    var end = ta.selectionEnd;
    var text = ta.value.substring(start, end);
    if (!text) {
        text = <?php echo json_encode(
            '<li>' . t('admin_pages_js_list_item', '列表项 {n}', ['n' => 1]) . "</li>\n"
            . '<li>' . t('admin_pages_js_list_item', '列表项 {n}', ['n' => 2]) . "</li>\n"
            . '<li>' . t('admin_pages_js_list_item', '列表项 {n}', ['n' => 3]) . '</li>'
        ); ?>;
    }
    ta.setRangeText('<' + type + '>\n' + text + '\n</' + type + '>', start, end, 'end');
    ta.focus();
    updatePreview();
    updateStats();
}

function insertBr() {
    var ta = document.getElementById('content');
    var start = ta.selectionStart;
    ta.setRangeText('<br>', start, start, 'end');
    ta.focus();
    updatePreview();
    updateStats();
}

function updatePreview() {
    var content = document.getElementById('content').value;
    var preview = document.getElementById('preview-pane');
    if (preview) {
        preview.innerHTML = content;
    }
}

function updateStats() {
    var el = document.getElementById('content-stats');
    if (el) {
        var len = document.getElementById('content').value.length;
        el.textContent = <?php echo json_encode(t('admin_pages_char_count_prefix', '字符数：')); ?> + len.toLocaleString();
    }
}
</script>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
