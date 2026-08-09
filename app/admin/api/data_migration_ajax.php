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

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!is_admin()) {
    echo json_encode(['success' => false, 'error' => t('admin_ajax_forbidden', '无权访问')], JSON_UNESCAPED_UNICODE);
    exit;
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

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'list_tables':
            // GET 请求也需校验 CSRF token，防止跨站攻击
            if (!validate_csrf()) {
                echo json_encode(['success' => false, 'error' => t('admin_ajax_csrf_failed', 'CSRF 校验失败')], JSON_UNESCAPED_UNICODE);
                exit;
            }
            listTables($MIGRATABLE_TABLES);
            break;

        case 'export':
            if (!validate_csrf()) {
                echo json_encode(['success' => false, 'error' => t('admin_ajax_csrf_failed', 'CSRF 校验失败')], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $format = $_POST['format'] ?? 'json';
            if ($format === 'sqlite' || $format === 'mysql') {
                // 只允许导出与"当前数据库类型"一致的 SQL 格式，避免生成无法导入的 SQL
                $driver = get_db_driver();
                $currentType = $driver->isFileBased() ? 'sqlite' : 'mysql';
                if ($format !== $currentType) {
                    echo json_encode([
                        'success' => false,
                        'error'   => t('admin_mig_format_db_mismatch_export',
                            '当前数据库类型为 {cur}，无法导出 {req} 格式。请选择与当前数据库一致的格式。',
                            ['cur' => $currentType, 'req' => $format]),
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                exportDataSQL($MIGRATABLE_TABLES, $format);
            } else {
                exportData($MIGRATABLE_TABLES, $MIGRATION_FORMAT);
            }
            break;

        case 'import':
            if (!validate_csrf()) {
                echo json_encode(['success' => false, 'error' => t('admin_ajax_csrf_failed', 'CSRF 校验失败')], JSON_UNESCAPED_UNICODE);
                exit;
            }
            importData($MIGRATABLE_TABLES, $MIGRATION_FORMAT);
            break;

        default:
            echo json_encode(['success' => false, 'error' => t('admin_mig_unknown_action', '未知操作')], JSON_UNESCAPED_UNICODE);
    }
} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'error'   => t('admin_mig_unexpected_error', '执行出错：') . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

/* ====================== 函数实现 ====================== */

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
    echo json_encode([
        'success' => true,
        'tables'  => $tables,
        'total'   => array_sum(array_column($tables, 'count')),
    ], JSON_UNESCAPED_UNICODE);
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
        echo json_encode(['success' => false, 'error' => t('admin_mig_no_table_selected', '请至少选择一张表进行导出')], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 仅允许白名单内的表
    $selected = array_values(array_intersect($requested, $whitelist));
    if (empty($selected)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => t('admin_mig_invalid_tables', '所选表不在可迁移范围内')], JSON_UNESCAPED_UNICODE);
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
        echo json_encode(['success' => false, 'error' => t('admin_mig_json_encode_failed', '导出数据过大或包含非法字符，导出失败')], JSON_UNESCAPED_UNICODE);
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
 * 将选中的业务表导出为 SQL 文件（CREATE TABLE + INSERT）
 * 兼容 MySQL / SQLite，可直接用 mysql / sqlite3 导入
 */
function exportDataSQL(array $whitelist, string $format = 'sql'): void {
    $requested = $_POST['tables'] ?? [];
    if (is_string($requested)) {
        $requested = explode(',', $requested);
    }
    $requested = array_filter(array_map('trim', (array)$requested), function ($t) { return $t !== ''; });

    if (empty($requested)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => t('admin_mig_no_table_selected', '请至少选择一张表进行导出')], JSON_UNESCAPED_UNICODE);
        return;
    }

    $selected = array_values(array_intersect($requested, $whitelist));
    if (empty($selected)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => t('admin_mig_invalid_tables', '所选表不在可迁移范围内')], JSON_UNESCAPED_UNICODE);
        return;
    }

    set_time_limit(300);

    $db = get_db();
    $driver = get_db_driver();
    $isSqlite = $driver->isFileBased();
    $isMysql = !$isSqlite && (defined('DB_TYPE') && DB_TYPE === 'mysql');

    $sql = "-- ============================================================\n";
    $sql .= "-- 云界论坛 数据库备份\n";
    $sql .= "-- 产品: yunjie-bbs | 版本: " . (defined('APP_VERSION') ? APP_VERSION : '') . "\n";
    $sql .= "-- 导出时间: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- 数据库: " . ($isSqlite ? 'SQLite' : ($isMysql ? 'MySQL' : 'PDO')) . "\n";
    $sql .= "-- ============================================================\n\n";
    $sql .= "-- DB-TYPE: " . ($isSqlite ? 'sqlite' : ($isMysql ? 'mysql' : 'unknown')) . "\n";
    $sql .= "SET NAMES utf8mb4;\n";
    if (!$isSqlite) {
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
    }

    foreach ($selected as $table) {
        $qi = function($n) use ($driver) { return $driver->quoteIdentifier($n); };
        $tableQ = $qi($table);

        // --- 表结构 ---
        $sql .= "-- -----------------------------------------------------------\n";
        $sql .= "-- 表结构: {$table}\n";
        $sql .= "-- -----------------------------------------------------------\n";

        if ($isMysql) {
            // MySQL: SHOW CREATE TABLE
            try {
                $stmt = $db->query("SHOW CREATE TABLE {$tableQ}");
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $createSql = $row['Create Table'] ?? '';
                if ($createSql) {
                    $sql .= "DROP TABLE IF EXISTS {$tableQ};\n\n{$createSql};\n\n";
                }
            } catch (\Throwable $e) {
                $sql .= "-- [警告] 无法获取 {$table} 的建表语句: " . $e->getMessage() . "\n\n";
            }
        } elseif ($isSqlite) {
            // SQLite: 从 sqlite_master 获取 CREATE 语句
            try {
                $stmt = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name=" . $db->quote($table));
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $createSql = $row['sql'] ?? '';
                if ($createSql) {
                    $sql .= "DROP TABLE IF EXISTS {$tableQ};\n\n{$createSql};\n\n";
                }
            } catch (\Throwable $e) {
                $sql .= "-- [警告] 无法获取 {$table} 的建表语句: " . $e->getMessage() . "\n\n";
            }
        } else {
            // PostgreSQL 或其他
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
                                // 转义单引号和特殊字符
                                $escaped = str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$val);
                                $values[] = "'" . $escaped . "'";
                            }
                        }
                        $sql .= "INSERT INTO {$tableQ} ({$colList}) VALUES (" . implode(', ', $values) . ");\n";
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

    // ===== 打包为 ZIP（含 SQL 文件 + uploads 目录） =====
    // 单独的 .sql 文件只包含数据库记录，不含头像等上传文件；
    // 打包成 ZIP 可确保导入后头像/附件等资源完整还原。
    $siteName = defined('SITE_NAME') ? SITE_NAME : '云界论坛';
    $zipFilename = $siteName . '_数据库备份_' . date('Ymd_His') . '.zip';
    $asciiName  = 'yunjie_backup_' . date('Ymd_His') . '.zip';

    $tmpZip = tempnam(sys_get_temp_dir(), 'mig_');
    if ($tmpZip === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => t('admin_mig_zip_failed', '无法创建临时文件')], JSON_UNESCAPED_UNICODE);
        return;
    }
    // tempnam 创建了空文件，ZipArchive::OVERWRITE 需要删除它
    @unlink($tmpZip);

    $zip = new ZipArchive();
    $res = $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($res !== true) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => t('admin_mig_zip_failed', '无法创建压缩包') . ' (code: ' . $res . ')'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 1) 写入 SQL 文件
    $sqlName = 'database_backup.sql';
    $zip->addFromString($sqlName, $sql);

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
            'product'     => 'yunjie-bbs',
            'version'     => defined('APP_VERSION') ? APP_VERSION : '',
            'exported_at' => date('Y-m-d H:i:s'),
            'db_type'     => $isSqlite ? 'sqlite' : ($isMysql ? 'mysql' : 'unknown'),
            'sql_file'    => $sqlName,
            'files_count' => $fileCount,
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
 * 导入迁移文件（支持 JSON、SQL 和 ZIP）
 */
function importData(array $whitelist, string $format): void {
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => t('admin_mig_no_file', '未收到上传文件')], JSON_UNESCAPED_UNICODE);
        return;
    }

    $file = $_FILES['file'];
    $name = strtolower($file['name'] ?? '');
    $isZip = (substr($name, -4) === '.zip');
    $isSql = (substr($name, -4) === '.sql');
    $isJson = (substr($name, -5) === '.json');

    if (!$isZip && !$isSql && !$isJson) {
        echo json_encode(['success' => false, 'error' => t('admin_mig_invalid_file', '仅支持 .json、.sql 或 .zip 迁移文件')], JSON_UNESCAPED_UNICODE);
        return;
    }
    // ZIP 包可能较大（含上传文件），放宽到 256MB
    $maxSize = $isZip ? (256 * 1024 * 1024) : (128 * 1024 * 1024);
    if ($file['size'] > $maxSize) {
        echo json_encode(['success' => false, 'error' => t('admin_mig_file_too_large', '文件过大（超过 ' . ($isZip ? '256' : '128') . 'MB）')], JSON_UNESCAPED_UNICODE);
        return;
    }

    // ===== ZIP 文件：解压 → 还原上传文件 → 提取 SQL 执行 =====
    if ($isZip) {
        importZipBackup($file['tmp_name'], $whitelist);
        return;
    }

    $raw = file_get_contents($file['tmp_name']);
    if ($raw === false || $raw === '') {
        echo json_encode(['success' => false, 'error' => t('admin_mig_file_read_failed', '无法读取上传文件')], JSON_UNESCAPED_UNICODE);
        return;
    }

    // ===== 禁止跨数据库类型迁移 =====
    // 检测文件所声明的源数据库类型（SQL 看 -- DB-TYPE 注释，JSON 看 source_driver 字段），
    // 与目标实例的当前数据库类型比对；不一致则拒绝，避免将 MySQL 数据导入 SQLite 或反之。
    $driver = get_db_driver();
    $currentType = $driver->isFileBased() ? 'sqlite' : 'mysql';
    $fileDbType = detectFileDbType($raw, $isSql);
    if ($fileDbType !== null && $fileDbType !== 'unknown' && $fileDbType !== $currentType) {
        echo json_encode([
            'success' => false,
            'error'   => t('admin_mig_cross_db_blocked',
                '不支持跨数据库类型迁移：文件来源为 {src}，当前数据库为 {cur}。请使用与源数据库相同类型的文件。',
                ['src' => $fileDbType, 'cur' => $currentType]),
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // SQL 文件 → 直接执行
    if ($isSql) {
        importSQL($raw);
        return;
    }

    // JSON 文件 → 原有逻辑
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        echo json_encode(['success' => false, 'error' => t('admin_mig_json_invalid', '文件不是有效的 JSON 数据')], JSON_UNESCAPED_UNICODE);
        return;
    }
    if (($data['format'] ?? '') !== $format) {
        echo json_encode(['success' => false, 'error' => t('admin_mig_format_mismatch', '文件格式不匹配，不是本系统导出的数据迁移文件')], JSON_UNESCAPED_UNICODE);
        return;
    }

    $mode = ($_POST['mode'] ?? 'overwrite') === 'merge' ? 'merge' : 'overwrite';

    set_time_limit(600);

    $db = get_db();
    $driver = get_db_driver();
    $isSqlite = $driver->isFileBased();

    // 1) 导入前创建快照，作为回滚安全网
    $snapshotName = '';
    try {
        $manager = new BackupManager();
        $snap = $manager->createBackup(t('admin_mig_snapshot_desc', '数据迁移导入前自动快照'));
        if (!empty($snap['filename'])) {
            $snapshotName = $snap['filename'];
        }
    } catch (\Throwable $e) {
        // 快照失败不阻断导入，但提示
        $snapshotName = '';
    }

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

    $results = [];
    $totalInserted = 0;
    $inTransaction = false;
    try {
        $db->beginTransaction();
        $inTransaction = true;

        foreach ($data['tables'] as $table => $rows) {
            if (!in_array($table, $whitelist, true)) {
                continue; // 跳过白名单外的表，避免误写系统表
            }
            if (!is_array($rows) || empty($rows)) {
                $results[$table] = 0;
                continue;
            }

            // 覆盖模式：先清空目标表
            if ($mode === 'overwrite') {
                $db->exec('DELETE FROM ' . $driver->quoteIdentifier($table));
            }

            $insertVerb = $mode === 'merge' ? $driver->insertIgnoreClause() : 'INSERT INTO';
            $inserted = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $cols = array_keys($row);
                if (empty($cols)) {
                    continue;
                }
                $colList = implode(', ', array_map([$driver, 'quoteIdentifier'], $cols));
                $placeholders = rtrim(str_repeat('?, ', count($cols)), ', ');
                $sql = $insertVerb . ' ' . $driver->quoteIdentifier($table) . ' (' . $colList . ') VALUES (' . $placeholders . ')';
                $stmt = $db->prepare($sql);
                $stmt->execute(array_values($row));
                $inserted++;
            }
            $totalInserted += $inserted;
            $results[$table] = $inserted;

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

        $db->commit();
        $inTransaction = false;
    } catch (\Throwable $e) {
        if ($inTransaction) {
            try { $db->rollBack(); } catch (\Throwable $e2) {}
        }
        // 恢复外键
        restoreForeignKeys($db, $isSqlite);
        echo json_encode([
            'success' => false,
            'error'   => t('admin_mig_import_failed', '导入失败：') . $e->getMessage(),
            'snapshot' => $snapshotName,
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 恢复外键约束
    restoreForeignKeys($db, $isSqlite);

    echo json_encode([
        'success'        => true,
        'mode'           => $mode,
        'total_inserted' => $totalInserted,
        'results'        => $results,
        'snapshot'       => $snapshotName,
        'message'        => ($mode === 'overwrite'
            ? t('admin_mig_import_done_overwrite', '覆盖导入完成，共写入 {n} 行数据。')
            : t('admin_mig_import_done_merge', '合并导入完成，共写入 {n} 行数据（已跳过主键冲突）。')
        ),
    ], JSON_UNESCAPED_UNICODE);
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
 */
function importSQL(string $sqlContent): void {
    set_time_limit(600);

    // 创建快照
    $snapshotName = '';
    try {
        $manager = new BackupManager();
        $snap = $manager->createBackup(t('admin_mig_snapshot_desc', '数据迁移导入前自动快照'));
        if (!empty($snap['filename'])) {
            $snapshotName = $snap['filename'];
        }
    } catch (\Throwable $e) {
        $snapshotName = '';
    }

    $db = get_db();
    $driver = get_db_driver();
    $isSqlite = $driver->isFileBased();

    // 关闭外键约束
    if ($isSqlite) {
        $db->exec('PRAGMA foreign_keys = OFF;');
    } else {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0;');
    }

    $lines = explode("\n", str_replace("\r\n", "\n", $sqlContent));
    $currentStmt = '';
    $executedCount = 0;
    $errorMessages = [];

    try {
        foreach ($lines as $lineNum => $line) {
            $trimmed = trim($line);

            // 跳过空行和纯注释
            if ($trimmed === '' || $trimmed[0] === '-' || $trimmed[0] === '#') {
                continue;
            }

            $currentStmt .= $line . "\n";

            // 以分号结尾 → 执行
            if (substr(trim($trimmed), -1) === ';') {
                $stmtSql = trim($currentStmt);
                $currentStmt = '';

                // 跳过 SET NAMES / SET FOREIGN_KEY_CHECKS 等环境语句（我们自行管理）
                if (preg_match('/^\s*(SET|PRAGMA)\s/i', $stmtSql)) {
                    continue;
                }

                try {
                    $db->exec($stmtSql);
                    $executedCount++;
                } catch (\Throwable $e) {
                    $errorMessages[] = 'Line ' . ($lineNum + 1) . ': ' . $e->getMessage();
                }
            }
        }

        // 恢复外键
        restoreForeignKeys($db, $isSqlite);

        echo json_encode([
            'success'         => !empty($errorMessages) ? false : true,
            'total_executed'  => $executedCount,
            'errors'          => $errorMessages,
            'snapshot'        => $snapshotName,
            'message'         => empty($errorMessages)
                ? t('admin_mig_sql_import_done', 'SQL 导入完成，共执行 {n} 条语句。', ['n' => $executedCount])
                : t('admin_mig_sql_import_partial', 'SQL 导入部分成功（{n} 条执行），{c} 条出错。', ['n' => $executedCount, 'c' => count($errorMessages)]),
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        restoreForeignKeys($db, $isSqlite);
        echo json_encode([
            'success' => false,
            'error'   => t('admin_mig_import_failed', '导入失败：') . $e->getMessage(),
            'snapshot' => $snapshotName,
        ], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * 导入 ZIP 备份包（含 SQL + uploads 目录）
 *
 * 流程：解压 ZIP → 还原 uploads/ 文件到项目目录 → 读取 .sql 执行导入
 */
function importZipBackup(string $tmpPath, array $whitelist): void {
    set_time_limit(600);

    $tmpExtract = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mig_extract_' . uniqid() . DIRECTORY_SEPARATOR;
    if (!mkdir($tmpExtract, 0755, true) && !is_dir($tmpExtract)) {
        echo json_encode(['success' => false, 'error' => t('admin_mig_zip_extract_failed', '无法创建解压目录')], JSON_UNESCAPED_UNICODE);
        return;
    }

    $zip = new ZipArchive();
    $res = $zip->open($tmpPath);
    if ($res !== true) {
        @rmdir($tmpExtract);
        echo json_encode(['success' => false, 'error' => t('admin_mig_zip_open_failed', '无法打开压缩包') . ' (code: ' . $res . ')'], JSON_UNESCAPED_UNICODE);
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
            }
            fclose($stream);
        }
    }
    $zip->close();

    if ($extractedCount === 0) {
        removeDirRecursive($tmpExtract);
        echo json_encode(['success' => false, 'error' => t('admin_mig_zip_empty', '压缩包为空或解压失败')], JSON_UNESCAPED_UNICODE);
        return;
    }

    // ===== 1) 还原上传文件（uploads/ → 项目 uploads/） =====
    $restoredFiles = 0;
    $uploadsSrc = $tmpExtract . 'uploads';
    $uploadsDest = defined('UPLOAD_PATH') ? UPLOAD_PATH : (ROOT_PATH . 'uploads' . DIRECTORY_SEPARATOR);
    if (is_dir($uploadsSrc)) {
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
                }
            }
        }
    }

    // ===== 2) 查找并执行 SQL 文件 =====
    $sqlFile = null;
    $candidates = glob($tmpExtract . '*.sql');
    if (!empty($candidates)) {
        $sqlFile = reset($candidates); // 取第一个 .sql 文件
    }
    if ($sqlFile === null || !is_file($sqlFile)) {
        removeDirRecursive($tmpExtract);
        echo json_encode([
            'success'        => false,
            'error'          => t('admin_mig_zip_no_sql', '压缩包中未找到 SQL 文件'),
            'files_restored' => $restoredFiles,
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $sqlContent = file_get_contents($sqlFile);
    if ($sqlContent === false || $sqlContent === '') {
        removeDirRecursive($tmpExtract);
        echo json_encode(['success' => false, 'error' => t('admin_mig_file_read_failed', '无法读取 SQL 文件')], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 跨库检测
    $driver = get_db_driver();
    $currentType = $driver->isFileBased() ? 'sqlite' : 'mysql';
    $fileDbType = detectFileDbType($sqlContent, true);
    if ($fileDbType !== null && $fileDbType !== 'unknown' && $fileDbType !== $currentType) {
        removeDirRecursive($tmpExtract);
        echo json_encode([
            'success' => false,
            'error'   => t('admin_mig_cross_db_blocked',
                '不支持跨数据库类型迁移：文件来源为 {src}，当前数据库为 {cur}。',
                ['src' => $fileDbType, 'cur' => $currentType]),
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 清理临时目录（SQL 已读入内存）
    removeDirRecursive($tmpExtract);

    // 执行 SQL 导入（复用 importSQL，它会创建快照 + 执行语句）
    importSQL($sqlContent);
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
