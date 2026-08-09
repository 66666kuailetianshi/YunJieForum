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
            listTables($MIGRATABLE_TABLES);
            break;

        case 'export':
            if (!validate_csrf()) {
                echo json_encode(['success' => false, 'error' => t('admin_ajax_csrf_failed', 'CSRF 校验失败')], JSON_UNESCAPED_UNICODE);
                exit;
            }
            exportData($MIGRATABLE_TABLES, $MIGRATION_FORMAT);
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
    $filename = $siteName . t('admin_mig_export_filename', '_数据迁移_') . date('Ymd_His') . '.json';
    $filename = str_replace([' ', '/', '\\', ':'], '_', $filename);

    // 直接输出下载（非 JSON 接口响应）
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($json));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    // BOM 保证 Excel/中文正确显示
    echo "\xEF\xBB\xBF";
    echo $json;
    exit;
}

/**
 * 导入 JSON 迁移文件
 */
function importData(array $whitelist, string $format): void {
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => t('admin_mig_no_file', '未收到上传文件')], JSON_UNESCAPED_UNICODE);
        return;
    }

    $file = $_FILES['file'];
    $name = strtolower($file['name'] ?? '');
    if (substr($name, -5) !== '.json') {
        echo json_encode(['success' => false, 'error' => t('admin_mig_invalid_file', '仅支持 .json 迁移文件')], JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($file['size'] > 64 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => t('admin_mig_file_too_large', '文件过大（超过 64MB），请分批迁移')], JSON_UNESCAPED_UNICODE);
        return;
    }

    $raw = file_get_contents($file['tmp_name']);
    if ($raw === false || $raw === '') {
        echo json_encode(['success' => false, 'error' => t('admin_mig_file_read_failed', '无法读取上传文件')], JSON_UNESCAPED_UNICODE);
        return;
    }
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
