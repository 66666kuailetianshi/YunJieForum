<?php
/**
 * 云界论坛 - 数据迁移 AJAX 接口
 *
 * 与"数据备份"（整库二进制/SQL 转储，用于同实例回滚）不同，本接口提供
 * **逻辑级数据迁移**：将选中的业务表导出为通用 JSON 格式，可在另一个实例
 * （甚至不同的数据库驱动）中逐行导入，实现跨实例数据迁移。
 *
 * 支持操作：
 *  - list_tables：返回白名单业务表及行数，供导出页面勾选
 *  - export：     将选中的业务表导出为 JSON 文件并下载
 *  - import：     上传 JSON 迁移文件，创建"导入前快照"后按表导入
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';
require_once APP_ROOT . 'app/includes/backup_manager.php';
require_once APP_ROOT . 'app/includes/db.php';

// 迁移导入前的快照范围：
// false（默认）= 仅备份本次迁移涉及的白名单业务表，mysqldump 耗时短，避免代理超时；
// true         = 备份完整数据库，安全性更高，但大库可能触发超时。
// 可在 data/site_config.php 中定义：define('MIGRATION_SNAPSHOT_FULL_DB', true);
if (!defined('MIGRATION_SNAPSHOT_FULL_DB')) {
    define('MIGRATION_SNAPSHOT_FULL_DB', false);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!is_admin()) {
    echo safeJsonEncode(['success' => false, 'error' => t('admin_ajax_forbidden', '无权访问')]);
    exit;
}

// 细粒度门禁：数据迁移仅超级管理员可用
if (!is_super_admin()) {
    echo safeJsonEncode(['success' => false, 'error' => t('common_super_admin_only', '该功能仅最高管理员可用。')]);
    exit;
}

/**
 * 安全 JSON 编码：失败时返回兜底 JSON，避免前端收到空响应导致「网络错误」。
 */
function safeJsonEncode($data): string {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        // 尝试清理无效 UTF-8 后再次编码
        $cleaned = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($cleaned === false) {
            return json_encode(['success' => false, 'error' => t('admin_mig_json_encode_failed', '服务器返回数据编码失败')]);
        }
        return $cleaned;
    }
    return $json;
}

// 可迁移的业务表白名单（与 db.php 中 ensure_*_table 保持一致）
$MIGRATABLE_TABLES = [
    'users', 'user_groups', 'roles', 'user_roles', 'user_medals', 'medals',
    'forum_categories', 'forums', 'posts', 'replies',
    'announcements', 'site_pages', 'site_settings',
    'notifications', 'reports', 'ban_appeals', 'password_reset_requests',
    'favorites', 'checkins', 'user_points_log',
    'pm_conversations', 'pm_messages',
    'mail_logs', 'mail_bounce_config', 'mail_bounce_logs',
    'sensitive_words', 'sensitive_word_whitelist', 'sensitive_word_logs', 'sensitive_word_status_logs',
    'traffic_stats', 'traffic_visitors',
];

$MIGRATION_FORMAT = 'yunjie-data-migration';

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['action'] ?? '') : '';

try {
    switch ($action) {
        case 'list_tables':
            // CSRF 校验（POST body 中的 csrf_token）
            if (!validate_csrf()) {
                echo safeJsonEncode(['success' => false, 'error' => t('admin_ajax_csrf_failed', 'CSRF 校验失败')]);
                exit;
            }
            listTables($MIGRATABLE_TABLES);
            break;

        case 'export':
            if (!validate_csrf()) {
                echo safeJsonEncode(['success' => false, 'error' => t('admin_ajax_csrf_failed', 'CSRF 校验失败')]);
                exit;
            }
            $format = $_POST['format'] ?? 'json';
            if ($format === 'sqlite' || $format === 'mysql') {
                // 只允许导出与"当前数据库类型"一致的 SQL 格式，避免生成无法导入的 SQL
                $driver = get_db_driver();
                $currentType = $driver->isFileBased() ? 'sqlite' : 'mysql';
                if ($format !== $currentType) {
                    echo safeJsonEncode([
                        'success' => false,
                        'error'   => t('admin_mig_format_db_mismatch_export',
                            '当前数据库类型为 {cur}，无法导出 {req} 格式。请选择与当前数据库一致的格式。',
                            ['cur' => $currentType, 'req' => $format]),
                    ]);
                    exit;
                }
                exportDataSQL($MIGRATABLE_TABLES, $format);
            } elseif ($format === 'json_zip') {
                exportDataJsonZip($MIGRATABLE_TABLES, $MIGRATION_FORMAT);
            } else {
                exportData($MIGRATABLE_TABLES, $MIGRATION_FORMAT);
            }
            break;

        case 'import':
            if (!validate_csrf()) {
                echo safeJsonEncode(['success' => false, 'error' => t('admin_ajax_csrf_failed', 'CSRF 校验失败')]);
                exit;
            }
            importData($MIGRATABLE_TABLES, $MIGRATION_FORMAT);
            break;

        case 'cleanup_duplicate_forums':
            if (!validate_csrf()) {
                echo safeJsonEncode(['success' => false, 'error' => t('admin_ajax_csrf_failed', 'CSRF 校验失败')]);
                exit;
            }
            cleanupDuplicateForums();
            break;

        default:
            echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_unknown_action', '未知操作')]);
    }
} catch (\Throwable $e) {
    echo safeJsonEncode([
        'success' => false,
        'error'   => t('admin_mig_unexpected_error', '执行出错：') . $e->getMessage(),
    ]);
}

/* ====================== 函数实现 ====================== */

/**
 * 心跳输出：在长时操作中定期调用，防止 Nginx/CDN/代理因连接空闲超时断开 HTTP/2。
 *
 * 原理：多数反向代理（Nginx/CDN）有 proxy_read_timeout（通常 60s），
 * 如果在这段时间内客户端↔代理之间没有数据传输，代理会主动断开连接，
 * 浏览器报 ERR_HTTP2_PING_FAILED / Failed to fetch。
 * 本函数每隔一定次数调用时输出一个空白注释并 flush，维持连接活跃。
 */
$_heartbeat_counter = 0;
function heartbeat(int $every = 50): void {
    global $_heartbeat_counter;
    $_heartbeat_counter++;
    if ($_heartbeat_counter % $every !== 0) return;
    // 输出一个 JSON 注释风格的保持活字节，不影响最终 json_decode
    echo " \n";
    if (ob_get_level() > 0) @ob_flush();
    @flush();
}

/**
 * 启用心跳模式：发送反缓冲头，确保后续 heartbeat() 能即时到达客户端。
 */
function startHeartbeat(): void {
    header('X-Accel-Buffering: no');       // 关闭 Nginx 缓冲
    header('Cache-Control: no-cache');       // 禁止任何缓存
    // 如果外部调用方正在使用 ob_start 捕获响应（如 ZIP 导入包装函数），
    // 则不清除缓冲，避免破坏响应捕获。
    if (empty($GLOBALS['_mig_capture_mode']) && ob_get_level() > 0) { @ob_end_clean(); }
}

/**
 * 返回白名单业务表及各自的行数
 */
function listTables(array $whitelist): void {
    $db = get_db();
    $driver = get_db_driver();
    $tables = [];
    foreach ($whitelist as $table) {
        $count = 0;
        try {
            $q = $db->query('SELECT COUNT(*) FROM ' . $driver->quoteIdentifier($table));
            if ($q) {
                $count = (int)$q->fetchColumn();
            }
        } catch (\Throwable $e) {
            // 表不存在则计数为 0
            $count = 0;
        }
        $tables[] = [
            'name'  => $table,
            'count' => $count,
        ];
    }
    echo safeJsonEncode([
        'success' => true,
        'tables'  => $tables,
        'total'   => array_sum(array_column($tables, 'count')),
    ]);
}

/**
 * 将选中的业务表导出为 JSON 并触发下载
 */
function exportData(array $whitelist, string $format): void {
    $requested = $_POST['tables'] ?? [];
    if (is_string($requested)) {
        $requested = explode(',', $requested);
    }
    $requested = array_filter(array_map('trim', (array)$requested), function ($t) { return $t !== ''; });

    if (empty($requested)) {
        http_response_code(400);
        echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_no_table_selected', '请至少选择一张表进行导出')]);
        return;
    }

    // 仅允许白名单内的表
    $selected = array_values(array_intersect($requested, $whitelist));
    if (empty($selected)) {
        http_response_code(400);
        echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_invalid_tables', '所选表不在可迁移范围内')]);
        return;
    }

    set_time_limit(300);

    $db = get_db();
    $driver = get_db_driver();
    $data = [
        'format'        => $format,
        'product'       => 'yunjie-bbs',
        'version'       => defined('APP_VERSION') ? APP_VERSION : '',
        'exported_at'   => time(),
        'source_driver' => $driver->isFileBased() ? 'sqlite' : (defined('DB_TYPE') ? DB_TYPE : 'mysql'),
        'tables'        => [],
    ];

    foreach ($selected as $table) {
        $rows = [];
        try {
            $q = $db->query('SELECT * FROM ' . $driver->quoteIdentifier($table));
            if ($q) {
                $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (\Throwable $e) {
            $rows = [];
        }
        // 将每行中的资源（如二进制）转为可读；SQLite 的 blob 已是字符串
        $data['tables'][$table] = $rows;
    }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        http_response_code(400);
        echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_json_encode_failed', '导出数据过大或包含非法字符，导出失败')]);
        return;
    }

    $siteName = defined('SITE_NAME') ? SITE_NAME : '云界论坛';
    $filename = $siteName . t('admin_mig_export_filename', '_数据迁移_') . date('Ymd_His');

    // 直接输出下载（非 JSON 接口响应）
    // 注意：不加 BOM，否则 json_decode 导入时会失败
    // filename 参数用纯英文兜底名（Windows 资源管理器不解码 URL 编码，必须 ASCII）；
    // filename* 用 RFC 5987 编码的中文原名（现代浏览器优先使用）
    $asciiName = 'yunjie_migration_' . date('Ymd_His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($filename) . '.json');
    header('Content-Length: ' . strlen($json));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $json;
    exit;
}

/**
 * 将选中的业务表导出为 JSON 并打包为 ZIP（含 uploads 头像/附件）
 * 这种 ZIP 内部是 migration.json，导入端可按 JSON 逻辑处理，支持合并/覆盖。
 */
function exportDataJsonZip(array $whitelist, string $format): void {
    $requested = $_POST['tables'] ?? [];
    if (is_string($requested)) {
        $requested = explode(',', $requested);
    }
    $requested = array_filter(array_map('trim', (array)$requested), function ($t) { return $t !== ''; });

    if (empty($requested)) {
        http_response_code(400);
        echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_no_table_selected', '请至少选择一张表进行导出')]);
        return;
    }

    $selected = array_values(array_intersect($requested, $whitelist));
    if (empty($selected)) {
        http_response_code(400);
        echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_invalid_tables', '所选表不在可迁移范围内')]);
        return;
    }

    set_time_limit(300);

    $db = get_db();
    $driver = get_db_driver();
    $data = [
        'format'        => $format,
        'product'       => 'yunjie-bbs',
        'version'       => defined('APP_VERSION') ? APP_VERSION : '',
        'exported_at'   => time(),
        'source_driver' => $driver->isFileBased() ? 'sqlite' : (defined('DB_TYPE') ? DB_TYPE : 'mysql'),
        'tables'        => [],
    ];

    foreach ($selected as $table) {
        $rows = [];
        try {
            $q = $db->query('SELECT * FROM ' . $driver->quoteIdentifier($table));
            if ($q) {
                $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (\Throwable $e) {
            $rows = [];
        }
        $data['tables'][$table] = $rows;
    }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        http_response_code(500);
        echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_json_encode_failed', '导出数据过大或包含非法字符，导出失败')]);
        return;
    }

    $siteName = defined('SITE_NAME') ? SITE_NAME : '云界论坛';
    $zipFilename = $siteName . '_数据迁移_含头像_' . date('Ymd_His') . '.zip';
    $asciiName   = 'yunjie_migration_' . date('Ymd_His') . '.zip';

    $tmpZip = tempnam(sys_get_temp_dir(), 'mig_');
    if ($tmpZip === false) {
        http_response_code(500);
        echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_zip_failed', '无法创建临时文件')]);
        return;
    }
    @unlink($tmpZip);

    $zip = new ZipArchive();
    $res = $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($res !== true) {
        http_response_code(500);
        echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_zip_failed', '无法创建压缩包') . ' (code: ' . $res . ')']);
        return;
    }

    // 1) JSON 迁移文件
    $zip->addFromString('migration.json', $json);

    // 2) 打包 uploads 目录
    $uploadsDir = defined('UPLOAD_PATH') ? UPLOAD_PATH : (ROOT_PATH . 'uploads' . DIRECTORY_SEPARATOR);
    $fileCount = 0;
    if (is_dir($uploadsDir)) {
        $baseLen = strlen(rtrim($uploadsDir, DIRECTORY_SEPARATOR)) + 1;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $localPath = 'uploads/' . substr($file->getPathname(), $baseLen);
                $zip->addFile($file->getPathname(), $localPath);
                $fileCount++;
            }
        }
    }

    // 3) 清单
    $zip->addFromString('manifest.json', json_encode([
        'product'     => 'yunjie-bbs',
        'version'     => defined('APP_VERSION') ? APP_VERSION : '',
        'exported_at' => date('Y-m-d H:i:s'),
        'format'      => 'json_zip',
        'json_file'   => 'migration.json',
        'files_count' => $fileCount,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $zip->close();

    $zipSize = filesize($tmpZip);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($zipFilename));
    header('Content-Length: ' . $zipSize);
    header('Cache-Control: no-cache, no-store, must-revalidate');

    readfile($tmpZip);
    @unlink($tmpZip);
    exit;
}

/**
 * 将选中的业务表导出为 SQL 文件（CREATE TABLE + INSERT）
 * 兼容 MySQL / SQLite，可直接用 mysql / sqlite3 导入
 */
/**
 * 生成单条 SQL 导出内容（覆盖或合并模式）
 */
function generateSQLContent(array $selected, string $mode, $db, $driver, bool $isSqlite, bool $isMysql): string {
    $qi = function($n) use ($driver) { return $driver->quoteIdentifier($n); };

    $sql = "-- ============================================================\n";
    $sql .= "-- 云界论坛 数据库备份\n";
    $sql .= "-- 产品: yunjie-bbs | 版本: " . (defined('APP_VERSION') ? APP_VERSION : '') . "\n";
    $sql .= "-- 导出时间: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- 数据库: " . ($isSqlite ? 'SQLite' : ($isMysql ? 'MySQL' : 'PDO')) . "\n";
    $sql .= "-- 导出模式: " . ($mode === 'merge' ? 'merge（合并）' : 'overwrite（覆盖）') . "\n";
    $sql .= "-- ============================================================\n\n";
    $sql .= "-- DB-TYPE: " . ($isSqlite ? 'sqlite' : ($isMysql ? 'mysql' : 'unknown')) . "\n";
    $sql .= "-- MIG-MODE: " . $mode . "\n";
    $sql .= "SET NAMES utf8mb4;\n";
    if (!$isSqlite) {
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
    }

    $insertVerb = 'INSERT INTO';
    if ($mode === 'merge') {
        $insertVerb = $isSqlite ? 'INSERT OR IGNORE INTO' : 'INSERT IGNORE INTO';
    }

    foreach ($selected as $table) {
        $tableQ = $qi($table);

        // --- 表结构 ---
        $sql .= "-- -----------------------------------------------------------\n";
        $sql .= "-- 表结构: {$table}\n";
        $sql .= "-- -----------------------------------------------------------\n";

        if ($isMysql) {
            try {
                $stmt = $db->query("SHOW CREATE TABLE {$tableQ}");
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $createSql = $row['Create Table'] ?? '';
                if ($createSql) {
                    if ($mode === 'overwrite') {
                        $sql .= "DROP TABLE IF EXISTS {$tableQ};\n\n{$createSql};\n\n";
                    } else {
                        // 合并模式：在 CREATE TABLE 后追加 IF NOT EXISTS，避免目标库已有表时报错。
                        // 直接替换表名前缀即可保留完整列定义、索引、字符集等元数据。
                        $createIf = preg_replace('/^\s*CREATE TABLE\s+(IF NOT EXISTS\s+)?/i', 'CREATE TABLE IF NOT EXISTS ', $createSql);
                        $sql .= $createIf . ";\n\n";
                    }
                }
            } catch (\Throwable $e) {
                $sql .= "-- [警告] 无法获取 {$table} 的建表语句: " . $e->getMessage() . "\n\n";
            }
        } elseif ($isSqlite) {
            try {
                $stmt = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name=" . $db->quote($table));
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $createSql = $row['sql'] ?? '';
                if ($createSql) {
                    if ($mode === 'overwrite') {
                        $sql .= "DROP TABLE IF EXISTS {$tableQ};\n\n{$createSql};\n\n";
                    } else {
                        $sql .= str_replace('CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $createSql) . ";\n\n";
                    }
                }
            } catch (\Throwable $e) {
                $sql .= "-- [警告] 无法获取 {$table} 的建表语句: " . $e->getMessage() . "\n\n";
            }
        } else {
            $sql .= "-- [提示] 当前数据库类型不支持自动生成建表语句，请手动确保目标库已有此表\n\n";
        }

        // --- 表数据 ---
        $sql .= "-- 数据: {$table}\n";
        try {
            $q = $db->query("SELECT * FROM {$tableQ}");
            if ($q) {
                $rows = $q->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    $cols = array_keys($rows[0]);
                    $colList = implode(', ', array_map($qi, $cols));
                    $rowCount = 0;
                    foreach ($rows as $row) {
                        $values = [];
                        foreach ($cols as $col) {
                            $val = $row[$col];
                            if ($val === null) {
                                $values[] = 'NULL';
                            } else {
                                // 转义反斜杠、单引号与换行符：换行不转义会导致 INSERT 跨多行，
                                // 导入端逐行解析时可能把内容行误判为注释/空行而截断语句
                                $escaped = str_replace(["\\", "'", "\r", "\n"], ["\\\\", "\\'", "\\r", "\\n"], (string)$val);
                                $values[] = "'" . $escaped . "'";
                            }
                        }
                        $sql .= "{$insertVerb} {$tableQ} ({$colList}) VALUES (" . implode(', ', $values) . ");\n";
                        $rowCount++;
                    }
                    $sql .= "-- 共 {$rowCount} 行\n\n";
                } else {
                    $sql .= "-- (空表)\n\n";
                }
            }
        } catch (\Throwable $e) {
            $sql .= "-- [错误] 读取 {$table} 数据失败: " . $e->getMessage() . "\n\n";
        }
    }

    if (!$isSqlite) {
        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    }
    $sql .= "-- 备份完成 --\n";

    return $sql;
}

/**
 * 将选中的业务表导出为 SQL 并打包为 ZIP（含覆盖+合并两种模式 + uploads 目录）
 */
function exportDataSQL(array $whitelist, string $format = 'sql'): void {
    $requested = $_POST['tables'] ?? [];
    if (is_string($requested)) {
        $requested = explode(',', $requested);
    }
    $requested = array_filter(array_map('trim', (array)$requested), function ($t) { return $t !== ''; });

    if (empty($requested)) {
        http_response_code(400);
        echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_no_table_selected', '请至少选择一张表进行导出')]);
        return;
    }

    $selected = array_values(array_intersect($requested, $whitelist));
    if (empty($selected)) {
        http_response_code(400);
        echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_invalid_tables', '所选表不在可迁移范围内')]);
        return;
    }

    set_time_limit(300);

    $db = get_db();
    $driver = get_db_driver();
    $isSqlite = $driver->isFileBased();
    $isMysql = !$isSqlite && (defined('DB_TYPE') && DB_TYPE === 'mysql');

    // 同时生成覆盖和合并两种模式的 SQL
    $sqlOverwrite = generateSQLContent($selected, 'overwrite', $db, $driver, $isSqlite, $isMysql);
    $sqlMerge     = generateSQLContent($selected, 'merge', $db, $driver, $isSqlite, $isMysql);

    // ===== 打包为 ZIP（含两个 SQL 文件 + uploads 目录） =====
    $siteName = defined('SITE_NAME') ? SITE_NAME : '云界论坛';
    $zipFilename = $siteName . '_数据库备份_' . date('Ymd_His') . '.zip';
    $asciiName  = 'yunjie_backup_' . date('Ymd_His') . '.zip';

    $tmpZip = tempnam(sys_get_temp_dir(), 'mig_');
    if ($tmpZip === false) {
        http_response_code(500);
        echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_zip_failed', '无法创建临时文件')]);
        return;
    }
    @unlink($tmpZip);

    $zip = new ZipArchive();
    $res = $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($res !== true) {
        http_response_code(500);
        echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_zip_failed', '无法创建压缩包') . ' (code: ' . $res . ')']);
        return;
    }

    // 1) 写入两个 SQL 文件（覆盖 + 合并）
    $sqlOverwriteName = 'database_overwrite.sql';
    $sqlMergeName     = 'database_merge.sql';
    $zip->addFromString($sqlOverwriteName, $sqlOverwrite);
    $zip->addFromString($sqlMergeName, $sqlMerge);

    // 2) 打包 uploads 目录（头像、图片等）
    $uploadsDir = defined('UPLOAD_PATH') ? UPLOAD_PATH : (ROOT_PATH . 'uploads' . DIRECTORY_SEPARATOR);
    if (is_dir($uploadsDir)) {
        $baseLen = strlen(rtrim($uploadsDir, DIRECTORY_SEPARATOR)) + 1;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        $fileCount = 0;
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $localPath = 'uploads/' . substr($file->getPathname(), $baseLen);
                $zip->addFile($file->getPathname(), $localPath);
                $fileCount++;
            }
        }
        // 在 ZIP 内写一个清单
        $zip->addFromString('manifest.json', json_encode([
            'product'       => 'yunjie-bbs',
            'version'       => defined('APP_VERSION') ? APP_VERSION : '',
            'exported_at'   => date('Y-m-d H:i:s'),
            'db_type'       => $isSqlite ? 'sqlite' : ($isMysql ? 'mysql' : 'unknown'),
            'sql_overwrite' => $sqlOverwriteName,
            'sql_merge'     => $sqlMergeName,
            'files_count'   => $fileCount,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    } else {
        // 无 uploads 目录时也写清单
        $zip->addFromString('manifest.json', json_encode([
            'product'       => 'yunjie-bbs',
            'version'       => defined('APP_VERSION') ? APP_VERSION : '',
            'exported_at'   => date('Y-m-d H:i:s'),
            'db_type'       => $isSqlite ? 'sqlite' : ($isMysql ? 'mysql' : 'unknown'),
            'sql_overwrite' => $sqlOverwriteName,
            'sql_merge'     => $sqlMergeName,
            'files_count'   => 0,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    $zip->close();

    $zipSize = filesize($tmpZip);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($zipFilename));
    header('Content-Length: ' . $zipSize);
    header('Cache-Control: no-cache, no-store, must-revalidate');

    readfile($tmpZip);
    @unlink($tmpZip);
    exit;
}

/**
 * 探测迁移文件声明的源数据库类型
 *  - SQL 文件：读取头部 -- DB-TYPE: sqlite|mysql|unknown 注释
 *  - JSON 文件：读取顶层 source_driver 字段
 * 返回 'sqlite' | 'mysql' | 'unknown' | null（null 表示无法确定）
 */
function detectFileDbType(string $raw, bool $isSql): ?string {
    if ($isSql) {
        $head = explode("\n", $raw, 80);
        foreach ($head as $line) {
            if (preg_match('/^--\s*DB-TYPE:\s*(sqlite|mysql|unknown)\s*$/i', $line, $m)) {
                $v = strtolower($m[1]);
                return $v === 'unknown' ? 'unknown' : $v;
            }
        }
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    $src = $data['source_driver'] ?? null;
    if ($src === 'sqlite' || $src === 'mysql') {
        return $src;
    }
    if (isset($data['tables']) && is_array($data['tables'])) {
        // 通用 JSON 迁移文件但缺少 source_driver：无法判定，放行（导入时按当前库结构写入）
        return null;
    }
    return null;
}

/**
 * 探测 SQL 迁移文件声明的导出模式
 * 读取头部 -- MIG-MODE: merge|overwrite 注释
 * 返回 'merge' | 'overwrite' | null（null 表示旧格式，默认按覆盖处理）
 */
function detectFileMigMode(string $sqlContent): ?string {
    $head = explode("\n", $sqlContent, 80);
    foreach ($head as $line) {
        if (preg_match('/^--\s*MIG-MODE:\s*(merge|overwrite)\s*$/i', $line, $m)) {
            return strtolower($m[1]);
        }
    }
    return null;
}

/**
 * 导入迁移文件（支持 JSON、SQL 和 ZIP）
 */
function importData(array $whitelist, string $format): void {
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_no_file', '未收到上传文件')]);
        return;
    }

    $file = $_FILES['file'];
    $name = strtolower($file['name'] ?? '');
    $isZip = (substr($name, -4) === '.zip');
    $isSql = (substr($name, -4) === '.sql');
    $isJson = (substr($name, -5) === '.json');

    if (!$isZip && !$isSql && !$isJson) {
        echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_invalid_file', '仅支持 .json、.sql 或 .zip 迁移文件')]);
        return;
    }
    // ZIP 包可能较大（含上传文件），放宽到 256MB
    $maxSize = $isZip ? (256 * 1024 * 1024) : (128 * 1024 * 1024);
    if ($file['size'] > $maxSize) {
        echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_file_too_large', '文件过大（超过 ' . ($isZip ? '256' : '128') . 'MB）')]);
        return;
    }

    // ===== ZIP 文件：解压 → 还原上传文件 → 提取 SQL/JSON 执行 =====
    if ($isZip) {
        importZipBackup($file['tmp_name'], $whitelist, $format);
        return;
    }

    $raw = file_get_contents($file['tmp_name']);
    if ($raw === false || $raw === '') {
        echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_file_read_failed', '无法读取上传文件')]);
        return;
    }

    // ===== 禁止跨数据库类型迁移 =====
    // 检测文件所声明的源数据库类型（SQL 看 -- DB-TYPE 注释，JSON 看 source_driver 字段），
    // 与目标实例的当前数据库类型比对；不一致则拒绝，避免将 MySQL 数据导入 SQLite 或反之。
    $driver = get_db_driver();
    $currentType = $driver->isFileBased() ? 'sqlite' : 'mysql';
    $fileDbType = detectFileDbType($raw, $isSql);
    if ($fileDbType !== null && $fileDbType !== 'unknown' && $fileDbType !== $currentType) {
        echo safeJsonEncode([
            'success' => false,
            'error'   => t('admin_mig_cross_db_blocked',
                '不支持跨数据库类型迁移：文件来源为 {src}，当前数据库为 {cur}。请使用与源数据库相同类型的文件。',
                ['src' => $fileDbType, 'cur' => $currentType]),
        ]);
        return;
    }

    // SQL 文件 → 直接执行（支持覆盖/合并两种模式）
    if ($isSql) {
        $mode = ($_POST['mode'] ?? 'overwrite') === 'merge' ? 'merge' : 'overwrite';
        importSQL($raw, [], $mode);
        return;
    }

    // JSON 文件 → 原有逻辑
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_json_invalid', '文件不是有效的 JSON 数据')]);
        return;
    }
    if (($data['format'] ?? '') !== $format) {
        echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_format_mismatch', '文件格式不匹配，不是本系统导出的数据迁移文件')]);
        return;
    }

    $mode = ($_POST['mode'] ?? 'overwrite') === 'merge' ? 'merge' : 'overwrite';
    importJsonData($data, $mode, $whitelist);
}

/**
 * 执行 JSON 数据导入（覆盖/合并）
 *
 * @param array  $data        已解析的迁移数据（含 tables）
 * @param string $mode        'overwrite' | 'merge'
 * @param array  $whitelist   允许写入的表白名单
 * @param string $snapshotName 已创建的快照名（外部已创建则传入，否则内部创建）
 */
function importJsonData(array $data, string $mode, array $whitelist, string $snapshotName = ''): void {
    set_time_limit(600);
    startHeartbeat(); // 启用心跳，防止长时导入被代理超时断连

    $db = get_db();
    $driver = get_db_driver();
    $isSqlite = $driver->isFileBased();

    // 1) 导入前创建快照，作为回滚安全网
    heartbeat(1); // 快照（尤其 MySQL mysqldump）可能耗时，先发一次心跳保持连接活跃
    if ($snapshotName === '') {
        try {
            $manager = new BackupManager();
            $snapTables = MIGRATION_SNAPSHOT_FULL_DB ? null : $whitelist;
            $snap = $manager->createBackup(t('admin_mig_snapshot_desc', '数据迁移导入前自动快照'), $snapTables);
            if (!empty($snap['filename'])) {
                $snapshotName = $snap['filename'];
            }
        } catch (\Throwable $e) {
            // 快照失败不阻断导入，但提示
            $snapshotName = '';
        }
    }
    heartbeat(1); // 快照结束后再次确认连接活跃

    // 2) 确保目标实例 schema 就绪
    if (function_exists('auto_migrate')) {
        auto_migrate();
    }

    // 3) 关闭外键约束，便于按任意顺序导入
    if ($isSqlite) {
        $db->exec('PRAGMA foreign_keys = OFF;');
    } else {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0;');
    }

    // 迁移拓扑顺序：被依赖的表优先导入，避免外键指向尚未导入的记录。
    // 注意：users.group_id 依赖 user_groups，因此 user_groups 必须在 users 之前。
    $migrationOrder = [
        'user_groups', 'roles', 'medals', 'forum_categories',
        'users',
        'forums',
        'user_roles', 'user_medals',
        'posts',
        'replies',
        'pm_conversations',
        'pm_messages',
        'favorites', 'checkins', 'user_points_log',
        'notifications', 'reports', 'ban_appeals', 'password_reset_requests',
        'announcements', 'site_pages', 'site_settings',
        'mail_logs', 'mail_bounce_config', 'mail_bounce_logs',
        'sensitive_words', 'sensitive_word_whitelist', 'sensitive_word_logs', 'sensitive_word_status_logs',
        'traffic_stats', 'traffic_visitors',
    ];

    // 合并模式下可重映射的核心表：主键冲突时自动分配新 ID，并同步更新下游外键引用。
    // 这能避免「主键冲突被跳过、但关联表仍指向旧 ID」导致的数据混乱。
    $remapConfig = [
        'users' => [
            'pk' => 'id',
            'refs' => [
                ['table' => 'posts', 'column' => 'user_id'],
                ['table' => 'replies', 'column' => 'user_id'],
                ['table' => 'pm_conversations', 'column' => 'user1_id'],
                ['table' => 'pm_conversations', 'column' => 'user2_id'],
                ['table' => 'pm_messages', 'column' => 'sender_id'],
                ['table' => 'favorites', 'column' => 'user_id'],
                ['table' => 'checkins', 'column' => 'user_id'],
                ['table' => 'user_points_log', 'column' => 'user_id'],
                ['table' => 'notifications', 'column' => 'user_id'],
                ['table' => 'reports', 'column' => 'reporter_id'],
                ['table' => 'reports', 'column' => 'handled_by'],
                ['table' => 'ban_appeals', 'column' => 'user_id'],
                ['table' => 'ban_appeals', 'column' => 'handled_by'],
                ['table' => 'password_reset_requests', 'column' => 'user_id'],
                ['table' => 'password_reset_requests', 'column' => 'handled_by'],
                ['table' => 'user_roles', 'column' => 'user_id'],
                ['table' => 'user_medals', 'column' => 'user_id'],
                ['table' => 'user_medals', 'column' => 'awarded_by'],
            ],
        ],
        // user_groups 通过 points 范围与用户关联，没有外键列直接引用，
        // 但合并导入时可能因主键冲突产生重复组，需要按 name 唯一键复用。
        'user_groups' => [
            'pk' => 'id',
            'unique' => ['name'],
            'refs' => [],
        ],
        'roles' => [
            'pk' => 'id',
            'refs' => [
                ['table' => 'user_roles', 'column' => 'role_id'],
            ],
        ],
        'medals' => [
            'pk' => 'id',
            'refs' => [
                ['table' => 'user_medals', 'column' => 'medal_id'],
            ],
        ],
        'forum_categories' => [
            'pk' => 'id',
            'unique' => ['name'],
            'refs' => [
                ['table' => 'forums', 'column' => 'category_id'],
            ],
        ],
        'forums' => [
            'pk' => 'id',
            'unique' => ['category_id', 'name'],
            'refs' => [
                ['table' => 'posts', 'column' => 'forum_id'],
            ],
        ],
        'posts' => [
            'pk' => 'id',
            'refs' => [
                ['table' => 'replies', 'column' => 'post_id'],
                ['table' => 'favorites', 'column' => 'post_id'],
                ['table' => 'reports', 'column' => 'post_id'],
            ],
        ],
        'replies' => [
            'pk' => 'id',
            'refs' => [
                ['table' => 'replies', 'column' => 'reply_to'],
                ['table' => 'reports', 'column' => 'reply_id'],
            ],
        ],
        'pm_conversations' => [
            'pk' => 'id',
            'unique' => ['user1_id', 'user2_id'],
            'refs' => [
                ['table' => 'pm_messages', 'column' => 'conversation_id'],
            ],
        ],
    ];

    // 按拓扑顺序排列；未在顺序中的表放在最后
    $orderedTables = [];
    foreach ($migrationOrder as $t) {
        if (isset($data['tables'][$t])) {
            $orderedTables[$t] = $data['tables'][$t];
        }
    }
    foreach ($data['tables'] as $t => $rows) {
        if (!array_key_exists($t, $orderedTables)) {
            $orderedTables[$t] = $rows;
        }
    }

    $results = [];
    $totalInserted = 0;
    $totalSkipped = 0;
    $rowErrors = [];
    $idMaps = [];          // ['表名' => [旧ID => 新ID], ...]
    $existingIdCache = [];
    $inTransaction = false;

    try {
        $db->beginTransaction();
        $inTransaction = true;

        foreach ($orderedTables as $table => &$rows) {
            if (!in_array($table, $whitelist, true)) {
                continue;
            }
            if (!is_array($rows) || empty($rows)) {
                $results[$table] = ['inserted' => 0, 'skipped' => 0, 'remapped' => 0];
                continue;
            }

            // 应用已生成的上游 ID 映射到本表数据，确保外键指向正确的新 ID
            foreach ($idMaps as $srcTable => $map) {
                if (empty($map) || !isset($remapConfig[$srcTable])) {
                    continue;
                }
                foreach ($remapConfig[$srcTable]['refs'] as $ref) {
                    if ($ref['table'] === $table) {
                        remapColumn($rows, $ref['column'], $map);
                    }
                }
            }

            // 会话表规范化：保证 user1_id < user2_id，与目标库 UNIQUE 约束一致
            if ($table === 'pm_conversations') {
                foreach ($rows as &$convRow) {
                    if (is_array($convRow) && isset($convRow['user1_id'], $convRow['user2_id'])
                        && (int)$convRow['user1_id'] > (int)$convRow['user2_id']) {
                        $tmp = $convRow['user1_id'];
                        $convRow['user1_id'] = $convRow['user2_id'];
                        $convRow['user2_id'] = $tmp;
                    }
                }
                unset($convRow);
            }

            // 覆盖模式：先清空目标表
            if ($mode === 'overwrite') {
                $db->exec('DELETE FROM ' . $driver->quoteIdentifier($table));
            }

            $inserted = 0;
            $skipped = 0;
            $remapped = 0;

            if ($mode === 'merge' && isset($remapConfig[$table])) {
                // 合并模式：可重映射表，主键冲突时自动分配新 ID
                $pk = $remapConfig[$table]['pk'];
                $existingIds = getExistingIds($db, $driver, $table, $pk, $existingIdCache);
                foreach ($rows as $idx => $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    // 用户表：导入前规范化，避免空用户名/邮箱产生空白行
                    if ($table === 'users') {
                        $row = normalizeUserImportRow($row);
                    }
                    heartbeat(30);
                    if (!array_key_exists($pk, $row)) {
                        $skipped++;
                        continue;
                    }
                    $oldId = $row[$pk];
                    $oldIdKey = is_numeric($oldId) ? (int)$oldId : (string)$oldId;
                    if (!in_array($oldIdKey, $existingIds, true)) {
                        // 主键不冲突；优先按业务唯一键复用已有记录，避免同名分类/版块等重复插入
                        $needInsert = true;
                        if (isset($remapConfig[$table]['unique'])) {
                            $uCols = $remapConfig[$table]['unique'];
                            $uVals = [];
                            $hasAll = true;
                            foreach ($uCols as $c) {
                                if (!array_key_exists($c, $row)) {
                                    $hasAll = false;
                                    break;
                                }
                                $uVals[] = $row[$c];
                            }
                            if ($hasAll) {
                                $existingUniqueId = findExistingByUnique($db, $driver, $table, $pk, $uCols, $uVals);
                                if ($existingUniqueId !== null) {
                                    $idMaps[$table][$oldId] = $existingUniqueId;
                                    $remapped++;
                                    $needInsert = false;
                                }
                            }
                        }
                        if ($needInsert) {
                            // 直接插入并保留原 ID
                            if (insertRow($db, $driver, $table, $row)) {
                                $inserted++;
                                $idMaps[$table][$oldId] = $oldId;
                                $existingIds[] = $oldIdKey;
                            } else {
                                $skipped++;
                                if (count($rowErrors) < 20) {
                                    $rowErrors[] = $table . '#' . ($idx + 1) . ': ' . t('admin_mig_insert_failed', '插入失败');
                                }
                            }
                        }
                    } else {
                        // 主键冲突：尝试按业务唯一键复用已有记录
                        $needRemap = true;
                        if (isset($remapConfig[$table]['unique'])) {
                            // 按业务唯一键优先复用已有记录 ID（避免同一分类/版块/会话重复创建）
                            $uCols = $remapConfig[$table]['unique'];
                            $uVals = [];
                            $hasAll = true;
                            foreach ($uCols as $c) {
                                if (!array_key_exists($c, $row)) {
                                    $hasAll = false;
                                    break;
                                }
                                $uVals[] = $row[$c];
                            }
                            if ($hasAll) {
                                $existingUniqueId = findExistingByUnique($db, $driver, $table, $pk, $uCols, $uVals);
                                if ($existingUniqueId !== null) {
                                    $idMaps[$table][$oldId] = $existingUniqueId;
                                    $remapped++;
                                    $needRemap = false;
                                }
                            }
                        }
                        if ($needRemap) {
                            $newRow = $row;
                            unset($newRow[$pk]);
                            $newId = insertRowReturningId($db, $driver, $table, $newRow, $pk);
                            if ($newId !== null) {
                                $idMaps[$table][$oldId] = $newId;
                                $remapped++;
                                $existingIds[] = is_numeric($newId) ? (int)$newId : (string)$newId;
                            } else {
                                $skipped++;
                                if (count($rowErrors) < 20) {
                                    $rowErrors[] = $table . '#' . ($idx + 1) . ': ' . t('admin_mig_remap_failed', '无法为冲突主键分配新 ID');
                                }
                            }
                        }
                    }
                }
            } else {
                // 覆盖模式 或 不可重映射表：批量插入
                $insertVerb = $mode === 'merge' ? $driver->insertIgnoreClause() : 'INSERT INTO';
                foreach ($rows as $idx => $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    // 用户表：导入前规范化，避免空用户名/邮箱产生空白行
                    if ($table === 'users') {
                        $row = normalizeUserImportRow($row);
                    }
                    heartbeat(30);
                    $cols = array_keys($row);
                    if (empty($cols)) {
                        continue;
                    }
                    $colList = implode(', ', array_map([$driver, 'quoteIdentifier'], $cols));
                    $placeholders = rtrim(str_repeat('?, ', count($cols)), ', ');
                    $sql = $insertVerb . ' ' . $driver->quoteIdentifier($table) . ' (' . $colList . ') VALUES (' . $placeholders . ')';
                    try {
                        $stmt = $db->prepare($sql);
                        $stmt->execute(array_values($row));
                        $inserted++;
                    } catch (\Throwable $e) {
                        // 合并模式下单条冲突/外键错误应跳过，避免整批失败
                        if ($mode === 'merge') {
                            $skipped++;
                            if (count($rowErrors) < 20) {
                                $rowErrors[] = $table . '#' . ($idx + 1) . ': ' . $e->getMessage();
                            }
                            continue;
                        }
                        // 覆盖模式下直接抛出，回滚事务
                        throw $e;
                    }
                }
            }

            $totalInserted += $inserted;
            $totalSkipped += $skipped;
            $results[$table] = ['inserted' => $inserted, 'skipped' => $skipped, 'remapped' => $remapped];

            // 对本表自引用列应用本表 ID 映射（例如 replies.reply_to）
            if ($mode === 'merge' && isset($remapConfig[$table]) && isset($idMaps[$table])) {
                foreach ($remapConfig[$table]['refs'] as $ref) {
                    if ($ref['table'] === $table) {
                        applyIdMapToDbColumn($db, $driver, $table, $ref['column'], $idMaps[$table]);
                    }
                }
            }

            // 覆盖模式后重置自增计数器
            if ($mode === 'overwrite' && $inserted > 0) {
                try {
                    if ($isSqlite) {
                        $db->prepare('DELETE FROM sqlite_sequence WHERE name = ?')->execute([$table]);
                    } else {
                        $db->exec('ALTER TABLE ' . $driver->quoteIdentifier($table) . ' AUTO_INCREMENT = 1');
                    }
                } catch (\Throwable $e) {
                    // 表无自增列时忽略
                }
            }
        }
        unset($rows); // 释放引用，避免意外修改 $orderedTables

        // 汇总重映射数量
        $totalRemapped = 0;
        foreach ($results as $r) {
            $totalRemapped += (int)($r['remapped'] ?? 0);
        }

        $db->commit();
        $inTransaction = false;
    } catch (\Throwable $e) {
        if ($inTransaction) {
            try { $db->rollBack(); } catch (\Throwable $e2) {}
        }
        // 恢复外键
        restoreForeignKeys($db, $isSqlite);
        echo safeJsonEncode([
            'success' => false,
            'error'   => t('admin_mig_import_failed', '导入失败：') . $e->getMessage(),
            'snapshot' => $snapshotName,
        ]);
        return;
    }

    // 恢复外键约束
    restoreForeignKeys($db, $isSqlite);

    $message = ($mode === 'overwrite'
        ? t('admin_mig_import_done_overwrite', '覆盖导入完成，共写入 {n} 行数据。', ['n' => $totalInserted])
        : t('admin_mig_import_done_merge', '合并导入完成，共写入 {n} 行数据（已自动处理主键冲突）。', ['n' => $totalInserted])
    );
    if ($mode === 'merge' && $totalSkipped > 0) {
        $message = t('admin_mig_import_done_merge_with_skip', '合并导入完成，共写入 {n} 行，跳过 {s} 行（主键冲突或外键约束）。', ['n' => $totalInserted, 's' => $totalSkipped]);
    }

    echo safeJsonEncode([
        'success'         => true,
        'mode'            => $mode,
        'total_inserted'  => $totalInserted,
        'total_skipped'   => $totalSkipped,
        'total_remapped'  => $totalRemapped,
        'results'         => $results,
        'snapshot'        => $snapshotName,
        'message'         => $message,
        'row_errors'      => $rowErrors,
    ]);
}

/**
 * 清理已导入的重复分类/版块/帖子/回复（合并导入因业务唯一键缺失导致的重复）。
 * 保留每组重复项中 id 最小的记录，将其余记录下的子数据迁移到保留记录后删除。
 * 清理完成后自动重新计算 users.posts_count，确保用户统计数据与实际帖子数一致。
 */
function cleanupDuplicateForums(): void {
    $db = get_db();
    $driver = get_db_driver();
    $isSqlite = $driver->isFileBased();

    // 临时关闭外键，避免更新/删除顺序触发约束异常
    if ($isSqlite) {
        $db->exec('PRAGMA foreign_keys = OFF;');
    } else {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0;');
    }

    $catMerged = 0;
    $forumMerged = 0;
    $postMerged = 0;
    $replyMerged = 0;
    $inTransaction = false;

    try {
        $db->beginTransaction();
        $inTransaction = true;

        // 1) 按 name 合并重复分类：保留最小 id
        $stmt = $db->query('SELECT id, name FROM forum_categories ORDER BY id ASC');
        $cats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $nameToKeepId = [];
        foreach ($cats as $cat) {
            $name = (string)$cat['name'];
            $dupId = (int)$cat['id'];
            if (!isset($nameToKeepId[$name])) {
                $nameToKeepId[$name] = $dupId;
                continue;
            }
            $keepId = $nameToKeepId[$name];
            $upd = $db->prepare('UPDATE forums SET category_id = ? WHERE category_id = ?');
            $upd->execute([$keepId, $dupId]);
            $catMerged += $upd->rowCount();
            $del = $db->prepare('DELETE FROM forum_categories WHERE id = ?');
            $del->execute([$dupId]);
        }

        // 2) 按 (category_id, name) 合并重复版块：保留最小 id
        $stmt = $db->query('SELECT id, category_id, name FROM forums ORDER BY id ASC');
        $forums = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $keyToKeepId = [];
        foreach ($forums as $forum) {
            $key = (int)$forum['category_id'] . '|' . (string)$forum['name'];
            $dupId = (int)$forum['id'];
            if (!isset($keyToKeepId[$key])) {
                $keyToKeepId[$key] = $dupId;
                continue;
            }
            $keepId = $keyToKeepId[$key];
            $upd = $db->prepare('UPDATE posts SET forum_id = ? WHERE forum_id = ?');
            $upd->execute([$keepId, $dupId]);
            $forumMerged += $upd->rowCount();
            $del = $db->prepare('DELETE FROM forums WHERE id = ?');
            $del->execute([$dupId]);
        }

        // 3) 按 (user_id, forum_id, title, content) 合并重复帖子：保留最小 id
        //    将重复帖子的回复迁移到保留帖子，更新保留帖子的回复数，然后删除重复帖子
        $stmt = $db->query('SELECT id, user_id, forum_id, title, content FROM posts ORDER BY id ASC');
        $allPosts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $postKeyToKeepId = [];
        $postIdsToDelete = [];
        foreach ($allPosts as $post) {
            $key = (int)$post['user_id'] . '|' . (int)($post['forum_id'] ?? 0) . '|' . (string)$post['title'] . '|' . (string)$post['content'];
            $dupId = (int)$post['id'];
            if (!isset($postKeyToKeepId[$key])) {
                $postKeyToKeepId[$key] = $dupId;
                continue;
            }
            $keepId = $postKeyToKeepId[$key];
            // 将重复帖子的回复迁移到保留帖子
            $updReply = $db->prepare('UPDATE replies SET post_id = ? WHERE post_id = ?');
            $updReply->execute([$keepId, $dupId]);
            $replyMerged += $updReply->rowCount();
            // 将引用该帖子的收藏/举报也迁移
            try { $db->prepare('UPDATE favorites SET post_id = ? WHERE post_id = ?')->execute([$keepId, $dupId]); } catch (\Throwable $e) {}
            try { $db->prepare('UPDATE reports SET post_id = ? WHERE post_id = ?')->execute([$keepId, $dupId]); } catch (\Throwable $e) {}
            $postIdsToDelete[] = $dupId;
            $postMerged++;
        }
        // 批量删除重复帖子
        if (!empty($postIdsToDelete)) {
            $placeholders = implode(',', array_fill(0, count($postIdsToDelete), '?'));
            $db->prepare('DELETE FROM posts WHERE id IN (' . $placeholders . ')')->execute($postIdsToDelete);
        }

        // 4) 按 (post_id, user_id, content) 合并重复回复：保留最小 id
        $stmt = $db->query('SELECT id, post_id, user_id, content FROM replies ORDER BY id ASC');
        $allReplies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $replyKeyToKeepId = [];
        $replyIdsToDelete = [];
        foreach ($allReplies as $reply) {
            $key = (int)$reply['post_id'] . '|' . (int)$reply['user_id'] . '|' . (string)$reply['content'];
            $dupId = (int)$reply['id'];
            if (!isset($replyKeyToKeepId[$key])) {
                $replyKeyToKeepId[$key] = $dupId;
                continue;
            }
            $keepId = $replyKeyToKeepId[$key];
            // 将引用该回复的 reply_to 指向保留回复
            try { $db->prepare('UPDATE replies SET reply_to = ? WHERE reply_to = ?')->execute([$keepId, $dupId]); } catch (\Throwable $e) {}
            // 将引用该回复的举报也迁移
            try { $db->prepare('UPDATE reports SET reply_id = ? WHERE reply_id = ?')->execute([$keepId, $dupId]); } catch (\Throwable $e) {}
            $replyIdsToDelete[] = $dupId;
            $replyMerged++;
        }
        // 批量删除重复回复
        if (!empty($replyIdsToDelete)) {
            $placeholders = implode(',', array_fill(0, count($replyIdsToDelete), '?'));
            $db->prepare('DELETE FROM replies WHERE id IN (' . $placeholders . ')')->execute($replyIdsToDelete);
        }

        // 5) 重新计算所有用户的 posts_count，确保与实际帖子数一致
        $db->exec('UPDATE users SET posts_count = (SELECT COUNT(*) FROM posts WHERE user_id = users.id)');

        // 6) 重新计算所有帖子的 replies_count，确保与实际回复数一致
        $db->exec('UPDATE posts SET replies_count = (SELECT COUNT(*) FROM replies WHERE post_id = posts.id)');

        $db->commit();
        $inTransaction = false;

        // 重置自增计数器
        try {
            if ($isSqlite) {
                $db->exec("DELETE FROM sqlite_sequence WHERE name IN ('forum_categories', 'forums', 'posts', 'replies')");
            } else {
                $db->exec('ALTER TABLE ' . $driver->quoteIdentifier('forum_categories') . ' AUTO_INCREMENT = 1');
                $db->exec('ALTER TABLE ' . $driver->quoteIdentifier('forums') . ' AUTO_INCREMENT = 1');
                $db->exec('ALTER TABLE ' . $driver->quoteIdentifier('posts') . ' AUTO_INCREMENT = 1');
                $db->exec('ALTER TABLE ' . $driver->quoteIdentifier('replies') . ' AUTO_INCREMENT = 1');
            }
        } catch (\Throwable $e) {
            // 忽略
        }

        if ($isSqlite) {
            $db->exec('PRAGMA foreign_keys = ON;');
        } else {
            $db->exec('SET FOREIGN_KEY_CHECKS = 1;');
        }

        echo safeJsonEncode([
            'success' => true,
            'message' => t('admin_mig_cleanup_done', '已清理重复数据：合并 {c} 个重复分类、{f} 个重复版块、{p} 个重复帖子、{r} 个重复回复，并已重新计算用户帖子统计。', ['c' => $catMerged, 'f' => $forumMerged, 'p' => $postMerged, 'r' => $replyMerged]),
            'cat_merged' => $catMerged,
            'forum_merged' => $forumMerged,
            'post_merged' => $postMerged,
            'reply_merged' => $replyMerged,
        ]);
    } catch (\Throwable $e) {
        if ($inTransaction) {
            try { $db->rollBack(); } catch (\Throwable $e2) {}
        }
        if ($isSqlite) {
            $db->exec('PRAGMA foreign_keys = ON;');
        } else {
            $db->exec('SET FOREIGN_KEY_CHECKS = 1;');
        }
        echo safeJsonEncode([
            'success' => false,
            'error'   => t('admin_mig_cleanup_failed', '清理失败：') . $e->getMessage(),
        ]);
    }
}

/**
 * 获取目标表中已存在的主键集合
 */
function getExistingIds(PDO $db, $driver, string $table, string $pk, array &$cache): array {
    $key = $table . '.' . $pk;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $ids = [];
    try {
        $stmt = $db->query('SELECT ' . $driver->quoteIdentifier($pk) . ' FROM ' . $driver->quoteIdentifier($table));
        if ($stmt) {
            while ($v = $stmt->fetchColumn()) {
                $ids[] = is_numeric($v) ? (int)$v : (string)$v;
            }
        }
    } catch (\Throwable $e) {
        // 表不存在则视为空
    }
    $cache[$key] = $ids;
    return $ids;
}

/**
 * 按唯一键查找已存在的记录主键
 */
function findExistingByUnique(PDO $db, $driver, string $table, string $pk, array $uCols, array $uVals): ?int {
    if (empty($uCols) || count($uCols) !== count($uVals)) {
        return null;
    }
    $conds = [];
    $params = [];
    foreach ($uCols as $i => $c) {
        $conds[] = $driver->quoteIdentifier($c) . ' = ?';
        $params[] = $uVals[$i];
    }
    try {
        $stmt = $db->prepare('SELECT ' . $driver->quoteIdentifier($pk) . ' FROM ' . $driver->quoteIdentifier($table) . ' WHERE ' . implode(' AND ', $conds) . ' LIMIT 1');
        $stmt->execute($params);
        $v = $stmt->fetchColumn();
        return $v !== false ? (int)$v : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * 插入单行（保留主键）
 */
function insertRow(PDO $db, $driver, string $table, array $row): bool {
    $cols = array_keys($row);
    if (empty($cols)) {
        return false;
    }
    $colList = implode(', ', array_map([$driver, 'quoteIdentifier'], $cols));
    $placeholders = rtrim(str_repeat('?, ', count($cols)), ', ');
    $sql = 'INSERT INTO ' . $driver->quoteIdentifier($table) . ' (' . $colList . ') VALUES (' . $placeholders . ')';
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute(array_values($row));
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * 插入单行（不指定主键），返回新主键
 */
function insertRowReturningId(PDO $db, $driver, string $table, array $row, string $pk): ?int {
    if (empty($row)) {
        return null;
    }
    $cols = array_keys($row);
    $colList = implode(', ', array_map([$driver, 'quoteIdentifier'], $cols));
    $placeholders = rtrim(str_repeat('?, ', count($cols)), ', ');
    $sql = 'INSERT INTO ' . $driver->quoteIdentifier($table) . ' (' . $colList . ') VALUES (' . $placeholders . ')';
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute(array_values($row));
        $id = $db->lastInsertId();
        return $id !== false && $id !== '' && $id !== '0' ? (int)$id : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * 规范化待导入的用户行：保证 username / email / password / uid 非空，
 * 避免合并导入后出现「用户名/邮箱/UID 为空」的空白行。
 * 仅当字段为空时才生成占位值，已存在的合法值原样保留。
 */
function normalizeUserImportRow(array $row): array {
    $base = $row['uid'] ?? $row['id'] ?? '';
    $suffix = is_numeric($base) ? (string)$base : substr(md5(serialize($row)), 0, 6);
    if (empty($row['username'])) {
        $row['username'] = 'user_' . $suffix;
    }
    if (empty($row['email'])) {
        $row['email'] = $row['username'] . '@migrated.local';
    }
    if (empty($row['password'])) {
        $row['password'] = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
    }
    // uid 为 UNIQUE 列，NULL 或空值会导致显示异常（用户列表出现空白行）
    if (!isset($row['uid']) || $row['uid'] === '' || $row['uid'] === null) {
        $row['uid'] = (int)(is_numeric($row['id'] ?? null) ? $row['id'] : (int)substr(md5(serialize($row)), 0, 8));
    }
    return $row;
}

/**
 * 根据映射关系更新数据集中的某一列
 */
function remapColumn(array &$rows, string $column, array $map): void {
    foreach ($rows as &$row) {
        if (!is_array($row)) {
            continue;
        }
        if (array_key_exists($column, $row)) {
            $v = $row[$column];
            if (isset($map[$v])) {
                $row[$column] = $map[$v];
            } elseif (is_numeric($v) && isset($map[(int)$v])) {
                $row[$column] = $map[(int)$v];
            }
        }
    }
    unset($row);
}

/**
 * 将数据库表中某一列的旧 ID 更新为新 ID（用于处理自引用表，如 replies.reply_to）
 */
function applyIdMapToDbColumn(PDO $db, $driver, string $table, string $column, array $map): void {
    if (empty($map)) {
        return;
    }
    $diffMap = [];
    foreach ($map as $old => $new) {
        if ((string)$old !== (string)$new) {
            $diffMap[$old] = $new;
        }
    }
    if (empty($diffMap)) {
        return;
    }

    $cases = [];
    $params = [];
    foreach ($diffMap as $old => $new) {
        $cases[] = 'WHEN ? THEN ?';
        $params[] = $old;
        $params[] = $new;
    }
    $colQ = $driver->quoteIdentifier($column);
    $tableQ = $driver->quoteIdentifier($table);
    $inPlaceholders = implode(', ', array_fill(0, count($diffMap), '?'));
    $sql = "UPDATE {$tableQ} SET {$colQ} = CASE {$colQ} " . implode(' ', $cases) . " END WHERE {$colQ} IN ({$inPlaceholders})";
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($params, array_keys($diffMap)));
    } catch (\Throwable $e) {
        // 自引用列更新失败不阻断导入
    }
}

/**
 * 恢复外键约束（按驱动差异）
 */
function restoreForeignKeys(PDO $db, bool $isSqlite): void {
    try {
        if ($isSqlite) {
            $db->exec('PRAGMA foreign_keys = ON;');
        } else {
            $db->exec('SET FOREIGN_KEY_CHECKS = 1;');
        }
    } catch (\Throwable $e) {
        // 忽略恢复失败
    }
}

/**
 * 执行 SQL 迁移文件导入（逐条执行，跳过注释和空行）
 *
 * @param string $sqlContent SQL 文件内容
 * @param array  $whitelist  可迁移表白名单
 * @param string $mode       导入模式：'overwrite'（覆盖）| 'merge'（合并）
 */
function importSQL(string $sqlContent, array $whitelist = [], string $mode = 'overwrite'): void {
    set_time_limit(600);
    startHeartbeat(); // 启用心跳，防止长时 SQL 导入被代理超时断连

    // 创建快照
    heartbeat(1); // 快照可能耗时，先发一次心跳保持连接活跃
    $snapshotName = '';
    try {
        $manager = new BackupManager();
        $snapTables = MIGRATION_SNAPSHOT_FULL_DB ? null : $whitelist;
        $snap = $manager->createBackup(t('admin_mig_snapshot_desc', '数据迁移导入前自动快照'), $snapTables);
        if (!empty($snap['filename'])) {
            $snapshotName = $snap['filename'];
        }
    } catch (\Throwable $e) {
        $snapshotName = '';
    }
    heartbeat(1); // 快照结束后再次确认连接活跃

    $db = get_db();
    $driver = get_db_driver();
    $isSqlite = $driver->isFileBased();

    // 合并模式：优先使用参数传入的模式（ZIP 导入时已根据用户选择选取了对应文件），
    // 其次使用文件内声明的模式（独立 .sql 文件导入时兼容旧格式）
    $fileMode = detectFileMigMode($sqlContent);
    if ($mode === 'overwrite' && $fileMode !== null) {
        // 参数为默认值且文件有声明，使用文件声明的模式
        $mode = $fileMode;
    }
    $isMerge = ($mode === 'merge');

    // 旧版 SQL 文件（无 -- MIG-MODE 标记）本质是覆盖格式：
    // 若用户选择合并模式，提示改用覆盖模式，避免误以为合并实际冲突报错
    if ($isMerge && $fileMode === null && preg_match('/^\s*DROP\s+TABLE\s+/im', $sqlContent)) {
        restoreForeignKeys($db, $isSqlite);
        echo safeJsonEncode([
            'success' => false,
            'error'   => t('admin_mig_sql_old_format_no_merge', '该 SQL 文件为旧版覆盖格式（无合并标记），不支持合并导入。请改用覆盖模式，或重新导出包含合并模式的 SQL 文件。'),
        ]);
        return;
    }

    // 关闭外键约束
    if ($isSqlite) {
        $db->exec('PRAGMA foreign_keys = OFF;');
    } else {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0;');
    }

    $lines = explode("\n", str_replace("\r\n", "\n", $sqlContent));
    $currentStmt = '';
    $executedCount = 0;
    $skippedCount = 0; // 合并模式下跳过的 DROP TABLE 语句数
    $errorMessages = [];
    $failedStatements = []; // 记录失败语句原文（用于诊断）
    $hasDDL = false; // 是否包含 DDL 语句（DROP/CREATE/ALTER），DDL 在 MySQL 中会隐式提交事务

    // 预扫描：检测是否包含 DDL 语句
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t !== '' && $t[0] !== '-' && $t[0] !== '#') {
            if (preg_match('/^\s*(DROP\s+TABLE|CREATE\s+TABLE|ALTER\s+TABLE)/i', $t)) {
                $hasDDL = true;
                break;
            }
        }
    }

    // 不含 DDL 的 SQL（纯 INSERT）可以用事务保护，出错时自动回滚
    $useTransaction = !$hasDDL;
    $inTransaction = false;

    if ($useTransaction) {
        try {
            $db->beginTransaction();
            $inTransaction = true;
        } catch (\Throwable $e) {
            // 事务开启失败则继续无事务模式
        }
    }

    try {
        // 引号感知分句器：逐字符跟踪单引号/双引号/反引号的闭合状态，
        // 只有不在任何字符串内且行尾为分号才结束语句。
        // 这样即使备份文件未转义换行（旧格式），字符串内的行尾分号也不会截断语句，
        // 避免 MySQL 报 "near ''" 语法错误。
        $inSQuote = false; // 单引号字符串内
        $inDQuote = false; // 双引号字符串内
        $inBQuote = false; // 反引号标识符内
        $escaped = false;  // 上一字符是反斜杠（转义状态，跨行延续）
        foreach ($lines as $lineNum => $line) {
            $trimmed = trim($line);

            // 语句未开始时：跳过空行和纯注释行；
            // 语句进行中（currentStmt 非空）：即使行以 --/# 开头或为空行也累加，
            // 因为多行 INSERT 的内容可能包含以 -- 开头的正文行，跳过会截断语句
            if ($currentStmt === '' && ($trimmed === '' || $trimmed[0] === '-' || $trimmed[0] === '#')) {
                continue;
            }

            $currentStmt .= $line . "\n";

            // 扫描本行字符，更新引号/转义状态
            $lineLen = strlen($line);
            for ($i = 0; $i < $lineLen; $i++) {
                $ch = $line[$i];
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escaped = true;
                    continue;
                }
                if (!$inDQuote && !$inBQuote && $ch === "'") { $inSQuote = !$inSQuote; continue; }
                if (!$inSQuote && !$inBQuote && $ch === '"') { $inDQuote = !$inDQuote; continue; }
                if (!$inSQuote && !$inDQuote && $ch === '`') { $inBQuote = !$inBQuote; continue; }
            }

            // 不在任何字符串内且行尾为分号 → 语句结束
            if (!$inSQuote && !$inDQuote && !$inBQuote && substr($trimmed, -1) === ';') {
                $stmtSql = trim($currentStmt);
                $currentStmt = '';

                // 跳过 SET NAMES / SET FOREIGN_KEY_CHECKS 等环境语句（我们自行管理）
                if (preg_match('/^\s*(SET|PRAGMA)\s/i', $stmtSql)) {
                    continue;
                }

                // 合并模式：跳过 DROP TABLE 语句（保留目标库已有数据）
                if ($isMerge && preg_match('/^\s*DROP\s+TABLE\s+/i', $stmtSql)) {
                    $skippedCount++;
                    continue;
                }

                try {
                    $db->exec($stmtSql);
                    $executedCount++;
                    heartbeat(20); // 每执行 20 条 SQL 发送一次心跳
                } catch (\Throwable $e) {
                    // 记录失败语句原文（截断到 500 字节），便于诊断 "near ''" 的真实原因
                    $failedSql = (strlen($stmtSql) > 500) ? (substr($stmtSql, 0, 500) . '...') : $stmtSql;
                    error_log('[MIGRATION] SQL exec failed at line ' . ($lineNum + 1) . ': ' . $e->getMessage() . ' | SQL: ' . $failedSql);
                    $errorMessages[] = 'Line ' . ($lineNum + 1) . ': ' . $e->getMessage();
                    $failedStatements[] = $failedSql;
                }
            }
        }

        // 文件末尾仍有未执行语句（最后一行无分号或旧文件被截断）：
        // 引号闭合则补执行，否则报告错误提示
        if ($currentStmt !== '') {
            if (!$inSQuote && !$inDQuote && !$inBQuote) {
                $stmtSql = trim($currentStmt);
                $currentStmt = '';
                if ($stmtSql !== '') {
                    try {
                        $db->exec($stmtSql);
                        $executedCount++;
                    } catch (\Throwable $e) {
                        $failedSql = (strlen($stmtSql) > 500) ? (substr($stmtSql, 0, 500) . '...') : $stmtSql;
                        error_log('[MIGRATION] SQL tail statement failed: ' . $e->getMessage() . ' | SQL: ' . $failedSql);
                        $errorMessages[] = t('admin_mig_sql_tail_error', '文件末尾语句执行失败：') . $e->getMessage();
                        $failedStatements[] = $failedSql;
                    }
                }
            } else {
                $errorMessages[] = t('admin_mig_sql_unclosed', '文件末尾存在未闭合的引号（语句被截断），请检查备份文件。');
            }
        }

        // 恢复外键
        restoreForeignKeys($db, $isSqlite);

        // 纯 INSERT 模式：无错误则提交，有错误则回滚
        if ($useTransaction && $inTransaction) {
            if (empty($errorMessages)) {
                $db->commit();
            } else {
                $db->rollBack();
            }
            $inTransaction = false;
        }

        $hasErrors = !empty($errorMessages);
        $response = [
            'success'         => !$hasErrors,
            'total_executed'  => $executedCount,
            'errors'          => $errorMessages,
            'snapshot'        => $snapshotName,
        ];
        if ($isMerge) {
            $response['skipped_drop'] = $skippedCount;
        }
        if (!empty($failedStatements)) {
            $response['failed_statements'] = array_slice($failedStatements, 0, 10);
        }
        if (empty($errorMessages)) {
            $msg = t('admin_mig_sql_import_done', 'SQL 导入完成，共执行 {n} 条语句。', ['n' => $executedCount]);
            if ($isMerge && $skippedCount > 0) {
                $msg .= ' ' . t('admin_mig_sql_merge_skipped', '合并模式：已跳过 {n} 条 DROP TABLE 语句，保留目标库已有数据。', ['n' => $skippedCount]);
            }
            $response['message'] = $msg;
        } else {
            $msg = t('admin_mig_sql_import_partial', 'SQL 导入部分成功（{n} 条执行），{c} 条出错。', ['n' => $executedCount, 'c' => count($errorMessages)]);
            // 含 DDL 的 SQL 无法回滚，提示用户从快照恢复
            if ($hasDDL) {
                $msg .= ' ' . t('admin_mig_sql_ddl_no_rollback', '由于包含建表/删表语句无法自动回滚，建议从快照恢复以确保数据一致性。');
            } else {
                $msg .= ' ' . t('admin_mig_sql_rolled_back', '已自动回滚所有更改。');
            }
            $response['message'] = $msg;
            // 前端失败分支显示 error 字段，这里同步填充（附前 5 条错误详情）
            $response['error'] = $msg;
            if (!empty($errorMessages)) {
                $response['error'] .= ' ' . t('admin_mig_sql_errors_detail', '错误详情：') . implode(' | ', array_slice($errorMessages, 0, 5));
            }
        }
        echo safeJsonEncode($response);
    } catch (\Throwable $e) {
        // 事务模式下自动回滚
        if ($useTransaction && $inTransaction) {
            try { $db->rollBack(); } catch (\Throwable $e2) {}
        }
        restoreForeignKeys($db, $isSqlite);
        echo safeJsonEncode([
            'success' => false,
            'error'   => t('admin_mig_import_failed', '导入失败：') . $e->getMessage(),
            'snapshot' => $snapshotName,
        ]);
    }
}

/**
 * 导入 ZIP 备份包（含 SQL/JSON + uploads 目录）
 *
 * 流程：解压 ZIP → 还原 uploads/ 文件到项目目录 → 读取 .sql 或 .json 执行导入
 */
function importZipBackup(string $tmpPath, array $whitelist, string $format): void {
    set_time_limit(600);
    startHeartbeat(); // 启用心跳，防止 ZIP 解压+导入被代理超时断连

    $tmpExtract = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mig_extract_' . uniqid() . DIRECTORY_SEPARATOR;
    if (!mkdir($tmpExtract, 0755, true) && !is_dir($tmpExtract)) {
        echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_zip_extract_failed', '无法创建解压目录')]);
        return;
    }

    $zip = new ZipArchive();
    $res = $zip->open($tmpPath);
    if ($res !== true) {
        @rmdir($tmpExtract);
        echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_zip_open_failed', '无法打开压缩包') . ' (code: ' . $res . ')']);
        return;
    }

    // 安全解压：禁止路径穿越（../）
    $extractedCount = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        // 跳过目录条目和路径穿越
        if (substr($name, -1) === '/' || strpos($name, '..') !== false) {
            continue;
        }
        $dest = $tmpExtract . str_replace('/', DIRECTORY_SEPARATOR, $name);
        $dir = dirname($dest);
        if (!is_dir($dir)) { mkdir($dir, 0755, true); }
        $stream = $zip->getStream($name);
            if ($stream) {
                $f = fopen($dest, 'wb');
                if ($f) {
                    stream_copy_to_stream($stream, $f);
                    fclose($f);
                    $extractedCount++;
                    heartbeat(50); // 每解压 50 个文件发送一次心跳
                }
                fclose($stream);
        }
    }
    $zip->close();

    if ($extractedCount === 0) {
        removeDirRecursive($tmpExtract);
        echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_zip_empty', '压缩包为空或解压失败')]);
        return;
    }

    // ===== 1) 先判断 ZIP 内是 JSON 迁移文件还是 SQL 迁移文件（先不还原文件） =====
    // 原则：先成功导入数据库，再还原上传文件，避免数据库导入失败时文件已无法回滚。
    $uploadsDest = defined('UPLOAD_PATH') ? UPLOAD_PATH : (ROOT_PATH . 'uploads' . DIRECTORY_SEPARATOR);
    $restoredFiles = 0;
    $jsonFile = null;
    $jsonCandidates = glob($tmpExtract . '*.json');
    foreach ($jsonCandidates as $candidate) {
        // 优先使用 manifest.json 中声明的 json_file，否则取第一个非 manifest 的 JSON
        $bn = basename($candidate);
        if ($bn === 'migration.json') {
            $jsonFile = $candidate;
            break;
        }
        if ($bn !== 'manifest.json' && $jsonFile === null) {
            $jsonFile = $candidate;
        }
    }

    // 如果有 JSON 迁移文件，按 JSON 方式导入（支持合并/覆盖）
    if ($jsonFile !== null && is_file($jsonFile)) {
        $raw = file_get_contents($jsonFile);
        if ($raw === false || $raw === '') {
            removeDirRecursive($tmpExtract);
            echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_file_read_failed', '无法读取 JSON 文件')]);
            return;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            removeDirRecursive($tmpExtract);
            echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_json_invalid', 'ZIP 内的迁移文件不是有效的 JSON 数据')]);
            return;
        }
        if (($data['format'] ?? '') !== $format) {
            removeDirRecursive($tmpExtract);
            echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_format_mismatch', 'ZIP 内迁移文件格式不匹配')]);
            return;
        }

        // 跨库检测
        $driver = get_db_driver();
        $currentType = $driver->isFileBased() ? 'sqlite' : 'mysql';
        $fileDbType = detectFileDbType($raw, false);
        if ($fileDbType !== null && $fileDbType !== 'unknown' && $fileDbType !== $currentType) {
            removeDirRecursive($tmpExtract);
            echo safeJsonEncode([
                'success' => false,
                'error'   => t('admin_mig_cross_db_blocked',
                    '不支持跨数据库类型迁移：文件来源为 {src}，当前数据库为 {cur}。',
                    ['src' => $fileDbType, 'cur' => $currentType]),
            ]);
            return;
        }

        $mode = ($_POST['mode'] ?? 'overwrite') === 'merge' ? 'merge' : 'overwrite';
        // 先导入数据库（成功），再还原上传文件，避免数据库导入失败时文件已无法回滚
        importJsonDataWithFileRestore($data, $mode, $whitelist, $tmpExtract, $uploadsDest, $restoredFiles);
        return;
    }

    // ===== 2) 无 JSON 则查找 SQL 文件 =====
    // 根据用户选择的模式，优先选择对应的 SQL 文件
    $mode = ($_POST['mode'] ?? 'overwrite') === 'merge' ? 'merge' : 'overwrite';
    $sqlFile = null;
    $candidates = glob($tmpExtract . '*.sql');
    if (!empty($candidates)) {
        // 优先按模式匹配文件名（database_merge.sql / database_overwrite.sql）
        $preferredName = $mode === 'merge' ? 'database_merge.sql' : 'database_overwrite.sql';
        foreach ($candidates as $candidate) {
            if (basename($candidate) === $preferredName) {
                $sqlFile = $candidate;
                break;
            }
        }
        // 如果没找到匹配模式的文件，回退到旧版单文件名或任意 .sql 文件
        if ($sqlFile === null) {
            foreach ($candidates as $candidate) {
                if (basename($candidate) === 'database_backup.sql') {
                    $sqlFile = $candidate;
                    break;
                }
            }
        }
        if ($sqlFile === null) {
            $sqlFile = reset($candidates);
        }
    }
    if ($sqlFile === null || !is_file($sqlFile)) {
        removeDirRecursive($tmpExtract);
        echo safeJsonEncode([
            'success'        => false,
            'error'          => t('admin_mig_zip_no_sql', '压缩包中未找到 SQL 或 JSON 迁移文件'),
            'files_restored' => $restoredFiles,
        ]);
        return;
    }

    $sqlContent = file_get_contents($sqlFile);
    if ($sqlContent === false || $sqlContent === '') {
        removeDirRecursive($tmpExtract);
        echo safeJsonEncode(['success' => false, 'error' => t('admin_mig_file_read_failed', '无法读取 SQL 文件')]);
        return;
    }

    // 跨库检测
    $driver = get_db_driver();
    $currentType = $driver->isFileBased() ? 'sqlite' : 'mysql';
    $fileDbType = detectFileDbType($sqlContent, true);
    if ($fileDbType !== null && $fileDbType !== 'unknown' && $fileDbType !== $currentType) {
        removeDirRecursive($tmpExtract);
        echo safeJsonEncode([
            'success' => false,
            'error'   => t('admin_mig_cross_db_blocked',
                '不支持跨数据库类型迁移：文件来源为 {src}，当前数据库为 {cur}。',
                ['src' => $fileDbType, 'cur' => $currentType]),
        ]);
        return;
    }

    // 先执行 SQL 导入（成功），再还原上传文件
    importSQLWithFileRestore($sqlContent, $whitelist, $tmpExtract, $uploadsDest, $restoredFiles, $mode);
}

/**
 * 还原上传文件（从解压临时目录 → 项目 uploads/）
 * 仅在数据库导入成功后调用，避免数据库导入失败时文件已无法回滚。
 */
function restoreUploadsFromExtract(string $tmpExtract, string $uploadsDest, int &$restoredFiles): void {
    $uploadsSrc = $tmpExtract . 'uploads';
    if (!is_dir($uploadsSrc)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadsSrc, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    $baseLen = strlen(rtrim($uploadsSrc, DIRECTORY_SEPARATOR)) + 1;
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $relative = substr($file->getPathname(), $baseLen);
            $targetDir = dirname($uploadsDest . $relative);
            if (!is_dir($targetDir)) { mkdir($targetDir, 0755, true); }
            if (copy($file->getPathname(), $uploadsDest . $relative)) {
                $restoredFiles++;
                heartbeat(50);
            }
        }
    }
}

/**
 * JSON 导入 + 成功后还原上传文件（包装 importJsonData）
 * 通过输出缓冲捕获 importJsonData 的 JSON 响应，判断是否成功后再还原文件。
 */
function importJsonDataWithFileRestore(array $data, string $mode, array $whitelist, string $tmpExtract, string $uploadsDest, int $alreadyRestored = 0): void {
    $restoredFiles = $alreadyRestored;
    // 设置捕获模式：阻止 startHeartbeat() 清除输出缓冲
    $GLOBALS['_mig_capture_mode'] = true;
    ob_start();
    importJsonData($data, $mode, $whitelist);
    $output = ob_get_clean();
    $GLOBALS['_mig_capture_mode'] = false;

    // 剥离心跳保持活字节（heartbeat() 输出的空白行），确保 JSON 解析正确
    $cleanOutput = trim(preg_replace('/^\s+$/m', '', $output));

    // 判断导入是否成功
    $result = json_decode($cleanOutput, true);
    $success = is_array($result) && !empty($result['success']);

    // 无论成功与否都输出原始响应
    echo $output;

    // 仅在数据库导入成功后还原上传文件
    if ($success) {
        restoreUploadsFromExtract($tmpExtract, $uploadsDest, $restoredFiles);
    }

    // 清理临时目录
    removeDirRecursive($tmpExtract);
}

/**
 * SQL 导入 + 成功后还原上传文件（包装 importSQL）
 */
function importSQLWithFileRestore(string $sqlContent, array $whitelist, string $tmpExtract, string $uploadsDest, int $alreadyRestored = 0, string $mode = 'overwrite'): void {
    $restoredFiles = $alreadyRestored;
    // 设置捕获模式：阻止 startHeartbeat() 清除输出缓冲
    $GLOBALS['_mig_capture_mode'] = true;
    ob_start();
    importSQL($sqlContent, $whitelist, $mode);
    $output = ob_get_clean();
    $GLOBALS['_mig_capture_mode'] = false;

    // 剥离心跳保持活字节，确保 JSON 解析正确
    $cleanOutput = trim(preg_replace('/^\s+$/m', '', $output));

    // 判断导入是否成功
    $result = json_decode($cleanOutput, true);
    $success = is_array($result) && !empty($result['success']);

    // 无论成功与否都输出原始响应
    echo $output;

    // 仅在数据库导入成功后还原上传文件
    if ($success) {
        restoreUploadsFromExtract($tmpExtract, $uploadsDest, $restoredFiles);
    }

    // 清理临时目录
    removeDirRecursive($tmpExtract);
}

/**
 * 递归删除目录
 */
function removeDirRecursive(string $dir): void {
    if (!is_dir($dir)) { return; }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) { @rmdir($item->getPathname()); }
        else { @unlink($item->getPathname()); }
    }
    @rmdir($dir);
}
