<?php
/**
 * 云界论坛 - 敏感词过滤辅助入口
 *
 * 论坛各处只需引入此文件即可使用：
 *   require_once __DIR__ . '/../sensitive_filter/helper.php';
 *   $filter = sensitive_filter();
 */

require_once __DIR__ . '/SensitiveFilter.php';

/**
 * 获取全局敏感词过滤实例（单例）
 */
function sensitive_filter(): SensitiveFilter {
    static $instance = null;
    if ($instance === null) {
        $db = get_db();
        $cacheFile = defined('DATA_PATH') ? DATA_PATH . 'cache/sensitive_filter.json' : __DIR__ . '/cache/sensitive_filter.json';
        $instance = new SensitiveFilter($db, $cacheFile);
        $instance->load();
    }
    return $instance;
}

/**
 * 快速检测文本是否包含敏感词
 */
function has_sensitive_words(string $text, int $minLevel = 1): bool {
    return sensitive_filter()->check($text, $minLevel);
}

/**
 * 快速替换文本中的 level 1 敏感词
 */
function filter_sensitive_words(string $text, ?array &$hits = null): string {
    return sensitive_filter()->filter($text, $hits);
}

/**
 * 获取文本命中的所有敏感词
 */
function find_sensitive_words(string $text): array {
    return sensitive_filter()->findAll($text);
}

/**
 * 记录敏感词命中日志
 */
function log_sensitive_words(int $userId, string $contentType, ?int $contentId, string $text, array $matches, string $action): void {
    sensitive_filter()->log($userId, $contentType, $contentId, $text, $matches, $action);
}

/**
 * 记录敏感词启用/禁用状态变更到审计日志表
 *
 * @param int    $wordId      敏感词 ID
 * @param string $word        敏感词内容（冗余存储，便于历史查询）
 * @param string $action      动作：'enable' 或 'disable'
 * @param int    $operatorId  操作者用户 ID
 * @param string $source      来源：manual(手动切换) / batch(批量操作) / edit(编辑保存)
 */
function log_sw_status_change(int $wordId, string $word, string $action, int $operatorId, string $source = 'manual'): void {
    if ($wordId <= 0) return;
    if (!in_array($action, ['enable', 'disable'], true)) return;
    if (!in_array($source, ['manual', 'batch', 'edit'], true)) $source = 'manual';

    try {
        $db = get_db();
        $operatorName = '';
        if ($operatorId > 0) {
            $stmt = $db->prepare("SELECT username FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $operatorId]);
            $operatorName = (string)$stmt->fetchColumn();
        }
        $stmt = $db->prepare("INSERT INTO sensitive_word_status_logs (word_id, word, action, operator_id, operator_name, source) VALUES (:wid, :word, :action, :oid, :oname, :source)");
        $stmt->execute([
            ':wid'    => $wordId,
            ':word'   => $word,
            ':action' => $action,
            ':oid'    => $operatorId,
            ':oname'  => $operatorName,
            ':source' => $source,
        ]);
    } catch (Exception $e) {
        error_log('log_sw_status_change failed: ' . $e->getMessage());
    }
}

/**
 * 清除敏感词缓存（管理后台修改词库后调用）
 */
function clear_sensitive_filter_cache(): void {
    sensitive_filter()->reload();
}

/**
 * 检测并处理文本中的敏感词。
 * 返回数组：max_level, matches, filtered
 *   max_level: 0=无命中, 1=仅替换级, 2=拦截级, 3=审核级
 *   matches: 命中的敏感词列表
 *   filtered: 替换 level 1 后的文本
 */
function sw_check_text(string $text): array {
    $filter = sensitive_filter();
    $matches = $filter->findAll($text);
    $maxLevel = 0;
    foreach ($matches as $m) {
        if ($m['level'] > $maxLevel) $maxLevel = $m['level'];
    }
    $filtered = $filter->filter($text);
    return [
        'max_level' => $maxLevel,
        'matches' => $matches,
        'filtered' => $filtered,
    ];
}

/**
 * 统一处理内容发布时的敏感词逻辑。
 * 命中 level 2 返回错误，命中 level 3 返回错误并提示进入审核。
 * level 1 自动替换并返回处理后的文本。
 */
function sw_process_content(string $text, string $contentType, int $userId, ?int $contentId, array &$errors): ?string {
    $result = sw_check_text($text);
    if ($result['max_level'] >= 2) {
        $words = array_column($result['matches'], 'word');
        if ($result['max_level'] >= 3) {
            $errors[] = t('sensitive_review_required', '内容包含需要人工审核的敏感词：{words}，请联系管理员。', ['words' => implode('、', array_slice($words, 0, 5))]);
            log_sensitive_words($userId, $contentType, $contentId, $text, $result['matches'], 'review');
        } else {
            $errors[] = t('sensitive_contains', '内容包含敏感词：{words}，请修改后重试。', ['words' => implode('、', array_slice($words, 0, 5))]);
            log_sensitive_words($userId, $contentType, $contentId, $text, $result['matches'], 'reject');
        }
        return null;
    }
    if (!empty($result['matches'])) {
        log_sensitive_words($userId, $contentType, $contentId, $text, $result['matches'], 'replace');
    }
    return $result['filtered'];
}
