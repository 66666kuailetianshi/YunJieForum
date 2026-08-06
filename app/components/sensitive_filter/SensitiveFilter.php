<?php
/**
 * 云界论坛 - 敏感词过滤引擎
 *
 * 特性：
 * 1. 基于 Trie + Aho-Corasick 自动机的高效多模式匹配。
 * 2. 支持精确匹配、整词匹配、正则匹配三种模式。
 * 3. 支持白名单机制，降低误伤。
 * 4. 支持分级处理：替换(level 1)、拦截(level 2)、人工审核(level 3)。
 * 5. 命中日志写入数据库。
 */

class SensitiveFilter {
    /** @var PDO */
    private $db;
    /** @var string|null */
    private $cacheFile;
    /** @var array */
    private $trie = [];
    /** @var array */
    private $regexWords = [];
    /** @var array */
    private $wordMap = [];
    /** @var array */
    private $whitelist = [];
    /** @var bool */
    private $loaded = false;

    public function __construct(PDO $db, ?string $cacheFile = null) {
        $this->db = $db;
        $this->cacheFile = $cacheFile;
    }

    /**
     * 加载词库并构建匹配引擎
     */
    public function load(): self {
        if ($this->loaded) {
            return $this;
        }

        $cache = $this->loadCache();
        if ($cache !== null) {
            $this->trie = $cache['trie'] ?? [];
            $this->regexWords = $cache['regexWords'] ?? [];
            $this->wordMap = $cache['wordMap'] ?? [];
            $this->whitelist = $cache['whitelist'] ?? [];
            $this->loaded = true;
            return $this;
        }

        $this->trie = ['children' => [], 'fail' => 0, 'outputs' => []];
        $this->regexWords = [];
        $this->wordMap = [];
        $this->whitelist = [];

        // 加载敏感词
        $stmt = $this->db->query("SELECT id, word, category, level, match_mode, replacement FROM sensitive_words WHERE enabled = 1 ORDER BY id ASC");
        $words = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $plainWords = [];
        foreach ($words as $w) {
            $w['level'] = (int)$w['level'];
            $word = (string)$w['word'];
            if ($word === '') continue;

            if ($w['match_mode'] === 'regex') {
                $this->regexWords[] = $w;
            } else {
                $plainWords[] = $w;
            }
        }

        $this->buildTrie($plainWords);

        // 加载白名单
        $stmt = $this->db->query("SELECT word FROM sensitive_word_whitelist WHERE enabled = 1");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $w) {
            if ((string)$w !== '') {
                $this->whitelist[] = (string)$w;
            }
        }

        $this->saveCache();
        $this->loaded = true;
        return $this;
    }

    /**
     * 重新加载词库（管理后台修改后调用）
     */
    public function reload(): self {
        $this->loaded = false;
        $this->clearCache();
        return $this->load();
    }

    /**
     * 构建 AC 自动机
     */
    private function buildTrie(array $words): void {
        $nodeId = 0;
        // 第一步：插入模式（词库统一转小写，与 findAll 中 lowercased 文本对齐，确保英文大小写不敏感匹配）
        foreach ($words as $w) {
            $word = $w['word'];
            $wordLower = mb_strtolower($word, 'UTF-8');
            $mode = $w['match_mode'];
            $current = 0;
            $len = mb_strlen($wordLower, 'UTF-8');
            for ($i = 0; $i < $len; $i++) {
                $ch = mb_substr($wordLower, $i, 1, 'UTF-8');
                if (!isset($this->trie[$current]['children'][$ch])) {
                    $nodeId++;
                    $this->trie[$nodeId] = ['children' => [], 'fail' => 0, 'outputs' => []];
                    $this->trie[$current]['children'][$ch] = $nodeId;
                }
                $current = $this->trie[$current]['children'][$ch];
            }
            $this->trie[$current]['outputs'][] = ['word' => $word, 'data' => $w];
            $this->wordMap[strtolower($word)] = $w;
        }

        // 第二步：构建失败指针（BFS）
        $queue = [];
        foreach ($this->trie[0]['children'] as $ch => $nodeId) {
            $this->trie[$nodeId]['fail'] = 0;
            $queue[] = $nodeId;
        }

        while (!empty($queue)) {
            $r = array_shift($queue);
            foreach ($this->trie[$r]['children'] as $ch => $nodeId) {
                $f = $this->trie[$r]['fail'];
                while ($f !== 0 && !isset($this->trie[$f]['children'][$ch])) {
                    $f = $this->trie[$f]['fail'];
                }
                if (isset($this->trie[$f]['children'][$ch])) {
                    $this->trie[$nodeId]['fail'] = $this->trie[$f]['children'][$ch];
                    $this->trie[$nodeId]['outputs'] = array_merge(
                        $this->trie[$nodeId]['outputs'],
                        $this->trie[$this->trie[$f]['children'][$ch]]['outputs']
                    );
                } else {
                    $this->trie[$nodeId]['fail'] = 0;
                }
                $queue[] = $nodeId;
            }
        }
    }

    /**
     * 在文本中查找所有命中（去重、包含位置信息）
     */
    public function findAll(string $text): array {
        $this->load();
        $matches = [];
        $textLower = mb_strtolower($text, 'UTF-8');
        $len = mb_strlen($textLower, 'UTF-8');

        $current = 0;
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($textLower, $i, 1, 'UTF-8');
            while ($current !== 0 && !isset($this->trie[$current]['children'][$ch])) {
                $current = $this->trie[$current]['fail'];
            }
            if (isset($this->trie[$current]['children'][$ch])) {
                $current = $this->trie[$current]['children'][$ch];
            }
            if (!isset($this->trie[$current]['outputs']) || !is_array($this->trie[$current]['outputs'])) {
                continue;
            }
            foreach ($this->trie[$current]['outputs'] as $output) {
                $word = $output['word'];
                $data = $output['data'];
                $key = $word . ':' . $data['match_mode'];
                if (isset($matches[$key])) continue;

                // 整词模式需校验边界
                if ($data['match_mode'] === 'word' && !$this->isWordBoundary($textLower, $i, mb_strlen($word, 'UTF-8'))) {
                    continue;
                }

                $matches[$key] = [
                    'id' => (int)$data['id'],
                    'word' => $word,
                    'category' => $data['category'],
                    'level' => (int)$data['level'],
                    'match_mode' => $data['match_mode'],
                    'replacement' => $data['replacement'],
                    'position' => $this->mbBytePos($text, $i),
                ];
            }
        }

        // 正则匹配
        foreach ($this->regexWords as $w) {
            if (@preg_match('/' . $w['word'] . '/iu', $text)) {
                $key = $w['word'] . ':regex';
                if (isset($matches[$key])) continue;
                $matches[$key] = [
                    'id' => (int)$w['id'],
                    'word' => $w['word'],
                    'category' => $w['category'],
                    'level' => (int)$w['level'],
                    'match_mode' => 'regex',
                    'replacement' => $w['replacement'],
                    'position' => 0,
                ];
            }
        }

        // 白名单校验：若命中词被白名单完全覆盖，则移除
        $matches = $this->applyWhitelist($text, $matches);

        return array_values($matches);
    }

    /**
     * 检查文本是否包含指定等级及以上的敏感词
     */
    public function check(string $text, int $minLevel = 1): bool {
        $matches = $this->findAll($text);
        foreach ($matches as $m) {
            if ($m['level'] >= $minLevel) {
                return true;
            }
        }
        return false;
    }

    /**
     * 获取文本中最高命中等级
     */
    public function maxLevel(string $text): int {
        $max = 0;
        foreach ($this->findAll($text) as $m) {
            if ($m['level'] > $max) $max = $m['level'];
        }
        return $max;
    }

    /**
     * 替换文本中的敏感词（仅 level 1 的词会被替换，level 2/3 保持原样并返回命中信息）
     */
    public function filter(string $text, ?array &$hits = null): string {
        $hits = $this->findAll($text);
        $replacements = [];
        foreach ($hits as $m) {
            if ($m['level'] === 1) {
                $replacements[$m['word']] = $m['replacement'];
            }
        }
        if (empty($replacements)) {
            return $text;
        }
        // 按长度降序替换，避免短词先替换影响长词
        uksort($replacements, function ($a, $b) {
            return mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8');
        });
        $result = $text;
        foreach ($replacements as $word => $replacement) {
            $result = $this->mbReplaceInsensitive($result, $word, $replacement);
        }
        return $result;
    }

    /**
     * 记录命中日志
     */
    public function log(int $userId, string $contentType, ?int $contentId, string $text, array $matches, string $action): void {
        if (empty($matches)) return;
        try {
            $stmt = $this->db->prepare("INSERT INTO sensitive_word_logs (user_id, content_type, content_id, matched_word, original_snippet, action) VALUES (:uid, :type, :cid, :word, :snippet, :action)");
            foreach ($matches as $m) {
                $snippet = $this->snippet($text, $m['position'] ?? 0, 60);
                $stmt->execute([
                    ':uid' => $userId,
                    ':type' => $contentType,
                    ':cid' => $contentId,
                    ':word' => $m['word'],
                    ':snippet' => $snippet,
                    ':action' => $action,
                ]);
            }
        } catch (Exception $e) {
            error_log('Sensitive word log failed: ' . $e->getMessage());
        }
    }

    /**
     * 整词边界检查
     */
    private function isWordBoundary(string $text, int $endPos, int $wordLen): bool {
        $startPos = $endPos - $wordLen + 1;
        if ($startPos < 0) return false;

        $before = $startPos > 0 ? mb_substr($text, $startPos - 1, 1, 'UTF-8') : '';
        $after = $endPos < mb_strlen($text, 'UTF-8') - 1 ? mb_substr($text, $endPos + 1, 1, 'UTF-8') : '';

        return !$this->isWordChar($before) && !$this->isWordChar($after);
    }

    private function isWordChar(?string $ch): bool {
        if ($ch === null || $ch === '') return false;
        if (preg_match('/[a-z0-9\x{4e00}-\x{9fff}]/iu', $ch)) return true;
        return false;
    }

    /**
     * 白名单应用：命中位置若被某个白名单短语覆盖，则移除该命中。
     *
     * 正确逻辑：遍历每个白名单短语在文本中出现的所有位置，记录其覆盖区间；
     * 若某个命中词的起始位置落在任一白名单区间内，则移除该命中。
     * 这样可以避免"文本其他位置的敏感词被错误豁免"的问题。
     */
    private function applyWhitelist(string $text, array $matches): array {
        if (empty($this->whitelist) || empty($matches)) return $matches;
        $textLower = mb_strtolower($text, 'UTF-8');

        // 计算所有白名单短语在文本中的覆盖区间
        $intervals = [];
        foreach ($this->whitelist as $white) {
            $whiteLower = mb_strtolower($white, 'UTF-8');
            if ($whiteLower === '') continue;
            $whiteLen = mb_strlen($whiteLower, 'UTF-8');
            $offset = 0;
            while (true) {
                $pos = mb_strpos($textLower, $whiteLower, $offset, 'UTF-8');
                if ($pos === false) break;
                $intervals[] = [$pos, $pos + $whiteLen]; // [start, end)
                $offset = $pos + 1; // 允许重叠匹配
            }
        }

        if (empty($intervals)) return $matches;

        // 按起始位置排序后合并区间，减少后续比较次数
        usort($intervals, function ($a, $b) { return $a[0] <=> $b[0]; });
        $merged = [];
        foreach ($intervals as $iv) {
            if (!empty($merged) && $iv[0] <= $merged[count($merged) - 1][1]) {
                $merged[count($merged) - 1][1] = max($merged[count($merged) - 1][1], $iv[1]);
            } else {
                $merged[] = $iv;
            }
        }

        // 过滤命中：命中词起始位置落在任一白名单区间内则移除
        foreach ($matches as $key => $m) {
            $wordLen = mb_strlen($m['word'], 'UTF-8');
            // position 是字节位置，需要转回字符位置
            $mbPos = mb_strlen(substr($text, 0, $m['position'] ?? 0), 'UTF-8');
            foreach ($merged as $iv) {
                if ($mbPos >= $iv[0] && $mbPos + $wordLen <= $iv[1]) {
                    unset($matches[$key]);
                    break;
                }
            }
        }
        return $matches;
    }

    private function mbReplaceInsensitive(string $text, string $search, string $replace): string {
        return preg_replace('/' . preg_quote($search, '/') . '/iu', $replace, $text);
    }

    private function mbBytePos(string $text, int $mbPos): int {
        return strlen(mb_substr($text, 0, $mbPos, 'UTF-8'));
    }

    private function snippet(string $text, int $bytePos, int $radius): string {
        $before = substr($text, max(0, $bytePos - $radius), min($bytePos, $radius));
        $after = substr($text, $bytePos, $radius);
        return mb_substr($before . $after, 0, $radius * 2, 'UTF-8');
    }

    private function loadCache(): ?array {
        if ($this->cacheFile === null || !is_file($this->cacheFile)) return null;
        $raw = @file_get_contents($this->cacheFile);
        if ($raw === false) return null;
        $data = @json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function saveCache(): void {
        if ($this->cacheFile === null) return;
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($this->cacheFile, json_encode([
            'trie' => $this->trie,
            'regexWords' => $this->regexWords,
            'wordMap' => $this->wordMap,
            'whitelist' => $this->whitelist,
            'built_at' => time(),
        ], JSON_UNESCAPED_UNICODE));
    }

    public function clearCache(): void {
        if ($this->cacheFile !== null && is_file($this->cacheFile)) {
            @unlink($this->cacheFile);
        }
    }
}
