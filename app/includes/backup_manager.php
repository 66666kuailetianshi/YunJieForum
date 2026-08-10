<?php
/**
 * 云界论坛 - 数据备份管理器
 *
 * 提供 SQLite / MySQL 数据库的备份、恢复、下载、删除功能。
 * 备份文件存储在 data/backups/ 目录，使用 gzip 压缩。
 *
 * 备份策略：
 *  - SQLite：优先使用 SQLite3::backup 在线备份，降级到文件复制
 *  - MySQL：  调用 mysqldump 导出为 .sql.gz
 *
 * 恢复策略：
 *  - SQLite：先创建"恢复前快照"，再使用 SQLite3::backup 或文件覆盖恢复
 *  - MySQL：  先创建"恢复前快照"，再使用 mysql 客户端导入
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

class BackupManager {
    /** @var string */
    private $backupDir;
    /** @var string */
    private $dbFile;
    /** @var bool */
    private $supported;
    /** @var array */
    private $dbConfig;

    public function __construct() {
        $driver = get_db_driver();
        $this->supported = true; // SQLite / MySQL 均支持
        $this->dbConfig = DatabaseFactory::loadConfig();
        $this->backupDir = DATA_PATH . 'backups' . DIRECTORY_SEPARATOR;
        $this->dbFile = $driver->isFileBased() ? $driver->getDbFile() : '';
        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0755, true);
        }
        // 保护备份目录，禁止外部直接访问（兼容 Apache 2.2/2.4）
        if (!is_file($this->backupDir . '.htaccess')) {
            @file_put_contents($this->backupDir . '.htaccess', "# Apache 2.4+\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n# Apache 2.2\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
        }
        if (!is_file($this->backupDir . 'index.html')) {
            @file_put_contents($this->backupDir . 'index.html', '');
        }
    }

    /**
     * 判断当前是否为 SQLite 数据库
     */
    public function isSqlite(): bool {
        return ($this->dbConfig['type'] ?? 'sqlite') === 'sqlite';
    }

    /**
     * 判断当前是否为 MySQL 数据库
     */
    public function isMysql(): bool {
        return ($this->dbConfig['type'] ?? 'sqlite') === 'mysql';
    }

    /**
     * 创建数据库备份
     *
     * @param string $description 备份描述（可选）
     * @return array ['success'=>bool, 'error'=>string, 'filename'=>string, 'meta'=>array]
     */
    public function createBackup(string $description = '', array $tables = null): array {
        $timestamp = date('Ymd_His');

        if ($this->isSqlite()) {
            return $this->createSqliteBackup($timestamp, $description, $tables);
        }

        if ($this->isMysql()) {
            return $this->createMysqlBackup($timestamp, $description, $tables);
        }

        return ['success' => false, 'error' => t('backup_unsupported_db_type', '不支持的数据库类型：') . ($this->dbConfig['type'] ?? 'unknown')];
    }

    /**
     * SQLite 备份
     */
    private function createSqliteBackup(string $timestamp, string $description, array $tables = null): array {
        $filename = 'backup_' . $timestamp . '.db.gz';
        $filepath = $this->backupDir . $filename;
        $tempDb = $this->backupDir . 'temp_' . $timestamp . '.db';

        // 优先使用 SQLite3::backup（在线备份，不影响活跃连接）
        $sqlite3Available = class_exists('SQLite3', false) && method_exists('SQLite3', 'backup');

        if ($sqlite3Available) {
            try {
                $source = new SQLite3($this->dbFile, SQLITE3_OPEN_READONLY);
                $dest = new SQLite3($tempDb);
                $source->backup($dest);
                $dest->close();
                $source->close();
            } catch (Exception $e) {
                $sqlite3Available = false;
                @unlink($tempDb);
            }
        }

        // 降级：先 wal_checkpoint，再直接复制文件
        if (!$sqlite3Available) {
            try {
                $db = get_db();
                $db->exec("PRAGMA wal_checkpoint(TRUNCATE)");
            } catch (Exception $e) {
                // 忽略 PRAGMA 失败
            }
            if (!@copy($this->dbFile, $tempDb)) {
                return ['success' => false, 'error' => t('backup_copy_db_failed', '无法复制数据库文件，请检查目录权限')];
            }
        }

        // gzip 压缩
        $data = @file_get_contents($tempDb);
        if ($data === false) {
            @unlink($tempDb);
            return ['success' => false, 'error' => t('backup_read_temp_failed', '无法读取临时备份文件')];
        }
        $gzData = gzencode($data, 9);
        if ($gzData === false) {
            @unlink($tempDb);
            return ['success' => false, 'error' => t('backup_compress_failed', '压缩备份文件失败')];
        }
        if (@file_put_contents($filepath, $gzData) === false) {
            @unlink($tempDb);
            return ['success' => false, 'error' => t('backup_write_file_failed', '无法写入备份文件，请检查目录权限')];
        }
        @unlink($tempDb);

        $meta = $this->buildMeta($filename, $description, filesize($filepath), filesize($this->dbFile));
        @file_put_contents($filepath . '.meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->autoCleanup(30);

        return ['success' => true, 'filename' => $filename, 'meta' => $meta];
    }

    /**
     * MySQL 备份（优先 mysqldump；exec 被禁用时自动回退纯 PHP 导出）
     */
    private function createMysqlBackup(string $timestamp, string $description, array $tables = null): array {
        // exec 被禁用（如宝塔面板默认安全配置禁用 exec/shell_exec）：回退到纯 PHP 导出
        if (!$this->canExec()) {
            return $this->createMysqlBackupPurePhp($timestamp, $description, $tables);
        }
        $filename = 'backup_' . $timestamp . '.sql.gz';
        $filepath = $this->backupDir . $filename;
        $tempSql = $this->backupDir . 'temp_' . $timestamp . '.sql';

        // 注意：mysqldump 的密码警告等 stderr 必须重定向到独立文件，
        // 不能合并进 SQL 文件（不要用 2>&1），否则警告行会污染备份文件首部，
        // 导致恢复时 mysql 报 ERROR 1064 语法错误。
        $errFile = $tempSql . '.err';
        $cmd = $this->buildMysqlDumpCommand($tempSql, $tables) . ' 2> ' . escapeshellarg($errFile);
        $output = [];
        $returnCode = 0;
        @exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || !is_file($tempSql) || filesize($tempSql) === 0) {
            $error = @file_get_contents($errFile);
            @unlink($errFile);
            @unlink($tempSql);
            return ['success' => false, 'error' => t('backup_mysqldump_failed', 'mysqldump 执行失败，请确保服务器已安装 mysqldump 并加入环境变量') . ($error ? t('backup_error_suffix', '：') . $error : '')];
        }
        @unlink($errFile);

        // gzip 压缩
        $data = @file_get_contents($tempSql);
        $gzData = gzencode($data, 9);
        if ($gzData === false) {
            @unlink($tempSql);
            return ['success' => false, 'error' => t('backup_compress_failed', '压缩备份文件失败')];
        }
        if (@file_put_contents($filepath, $gzData) === false) {
            @unlink($tempSql);
            return ['success' => false, 'error' => t('backup_write_file_failed', '无法写入备份文件，请检查目录权限')];
        }
        @unlink($tempSql);

        $dbSize = $this->getMysqlDatabaseSize();
        $meta = $this->buildMeta($filename, $description, filesize($filepath), $dbSize);
        @file_put_contents($filepath . '.meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->autoCleanup(30);

        return ['success' => true, 'filename' => $filename, 'meta' => $meta];
    }

    /**
     * 检测 exec 是否可用（部分面板默认将其加入 disable_functions）
     */
    private function canExec(): bool {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        return !in_array('exec', $disabled, true);
    }

    /**
     * 纯 PHP 导出 MySQL 为 SQL 字符串（PDO 遍历表，不依赖 mysqldump/exec）
     *
     * 输出 DROP TABLE + SHOW CREATE TABLE + 逐行 INSERT，与 mysqldump 产物兼容恢复逻辑
     * （恢复时校验 CREATE TABLE / INSERT INTO，且支持 PDO 多语句执行恢复）。
     *
     * @return array ['ok'=>bool, 'sql'=>string, 'error'=>string]
     */
    private function mysqlDumpToSqlViaPdo(array $tables = null): array {
        try {
            $config = $this->dbConfig;
            $host = $config['host'] ?? 'localhost';
            $port = $config['port'] ?? 3306;
            $dbname = $config['dbname'] ?? '';
            $dsn = 'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4';
            if ($dbname !== '') {
                $dsn .= ';dbname=' . $dbname;
            }
            $pdo = new PDO($dsn, $config['user'] ?? '', $config['pass'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            // 需要导出的表
            if (!empty($tables) && is_array($tables)) {
                $tableList = array_values(array_filter(array_map('strval', $tables)));
            } else {
                $tableList = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            }
            if (empty($tableList)) {
                return ['ok' => false, 'sql' => '', 'error' => t('backup_no_tables', '未找到可备份的数据表')];
            }

            $sql = '-- 云界论坛 MySQL 备份（纯 PHP 导出，未使用 mysqldump）' . "\n"
                 . '-- 时间: ' . date('Y-m-d H:i:s') . "\n"
                 . '-- 数据库: ' . $dbname . "\n"
                 . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

            foreach ($tableList as $table) {
                $table = (string)$table;
                if ($table === '') {
                    continue;
                }
                $quotedTable = '`' . str_replace('`', '``', $table) . '`';

                // 建表语句（SHOW CREATE TABLE 返回 [表名, 建表语句]，取最后一个）
                $create = $pdo->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_ASSOC);
                if (is_array($create)) {
                    $createSql = end($create);
                    $sql .= 'DROP TABLE IF EXISTS ' . $quotedTable . ";\n" . $createSql . ";\n\n";
                }

                // 逐行导出数据（避免大表一次性 fetchAll 占用过多内存）
                $stmt = $pdo->query('SELECT * FROM ' . $quotedTable);
                $first = true;
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $cols = array_map(function ($c) {
                        return '`' . str_replace('`', '``', (string)$c) . '`';
                    }, array_keys($row));
                    $vals = [];
                    foreach ($row as $v) {
                        if ($v === null) {
                            $vals[] = 'NULL';
                        } elseif (is_int($v) || is_float($v)) {
                            $vals[] = (string)$v;
                        } else {
                            $vals[] = $pdo->quote((string)$v);
                        }
                    }
                    $sql .= 'INSERT INTO ' . $quotedTable . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ");\n";
                    $first = false;
                }
                if (!$first) {
                    $sql .= "\n";
                }
            }
            $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
            return ['ok' => true, 'sql' => $sql, 'error' => ''];
        } catch (Exception $e) {
            return ['ok' => false, 'sql' => '', 'error' => $e->getMessage()];
        }
    }

    /**
     * MySQL 备份（纯 PHP 导出，exec/mysqldump 不可用时的兜底方案）
     */
    private function createMysqlBackupPurePhp(string $timestamp, string $description, array $tables = null): array {
        $filename = 'backup_' . $timestamp . '.sql.gz';
        $filepath = $this->backupDir . $filename;

        $res = $this->mysqlDumpToSqlViaPdo($tables);
        if (!$res['ok']) {
            return ['success' => false, 'error' => t('backup_purephp_failed', '纯 PHP 导出失败') . ($res['error'] !== '' ? t('backup_error_suffix', '：') . $res['error'] : '')];
        }

        $gzData = gzencode($res['sql'], 9);
        if ($gzData === false) {
            return ['success' => false, 'error' => t('backup_compress_failed', '压缩备份文件失败')];
        }
        if (@file_put_contents($filepath, $gzData) === false) {
            return ['success' => false, 'error' => t('backup_write_file_failed', '无法写入备份文件，请检查目录权限')];
        }

        $dbSize = $this->getMysqlDatabaseSize();
        $meta = $this->buildMeta($filename, $description, filesize($filepath), $dbSize);
        $meta['created_by'] = 'pure_php';
        @file_put_contents($filepath . '.meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->autoCleanup(30);

        return ['success' => true, 'filename' => $filename, 'meta' => $meta];
    }

    /**
     * 自动寻找 MySQL 客户端二进制文件（优先 phpstudy 常见路径，兼容 Linux/Windows）
     */
    private function findMysqlBinary(string $name): string {
        $isWindows = stripos(PHP_OS, 'WIN') === 0;
        $ext = $isWindows ? '.exe' : '';

        // 1. 已在 PATH 中可直接使用（Windows: where；Linux/macOS: which）
        $output = [];
        $returnCode = -1;
        if (function_exists('exec')) {
            $cmd = $isWindows ? 'where ' : 'which ';
            @exec($cmd . escapeshellarg($name) . ' 2>&1', $output, $returnCode);
        }
        if ($returnCode === 0 && !empty($output[0]) && is_file($output[0])) {
            return $output[0];
        }

        // 2. 常见系统 PATH
        $candidates = [];
        if ($isWindows) {
            $candidates[] = 'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\' . $name . '.exe';
            $candidates[] = 'C:\\Program Files (x86)\\MySQL\\MySQL Server 5.7\\bin\\' . $name . '.exe';
            $candidates[] = 'C:\\xampp\\mysql\\bin\\' . $name . '.exe';
            $candidates[] = 'C:\\wamp64\\bin\\mysql\\mysql8.0.21\\bin\\' . $name . '.exe';
            $candidates[] = 'C:\\wamp\\bin\\mysql\\mysql8.0.21\\bin\\' . $name . '.exe';

            // 扫描 phpstudy 常见目录
            foreach (['E:\\', 'C:\\', 'D:\\'] as $drive) {
                $baseDir = $drive . 'phpstudy_pro\\Extensions';
                if (!is_dir($baseDir)) continue;
                $dirs = @glob($baseDir . '\\MySQL*', GLOB_ONLYDIR);
                if (!is_array($dirs)) continue;
                foreach ($dirs as $dir) {
                    $candidates[] = $dir . '\\bin\\' . $name . '.exe';
                }
            }
        } else {
            // Linux / macOS 常见路径
            $candidates[] = '/usr/bin/' . $name;
            $candidates[] = '/usr/local/mysql/bin/' . $name;
            $candidates[] = '/usr/local/mariadb/bin/' . $name;
            $candidates[] = '/opt/mysql/bin/' . $name;
            $candidates[] = '/opt/lampp/bin/' . $name;
            $candidates[] = '/usr/local/opt/mysql/bin/' . $name; // macOS Homebrew
        }

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        // 3. 通过已找到的 mysqldump 同目录推断 mysql（很多环境只把其中一个加到 PATH）
        if ($name === 'mysql') {
            $dumpPath = $this->findMysqlBinary('mysqldump');
            if ($dumpPath !== 'mysqldump' && is_file($dumpPath)) {
                $dir = dirname($dumpPath);
                $sibling = $dir . DIRECTORY_SEPARATOR . 'mysql' . $ext;
                if (is_file($sibling)) {
                    return $sibling;
                }
            }
        }

        // 4. 回退到命令名，依赖系统 PATH
        return $name;
    }

    /**
     * 构造 mysqldump 命令
     */
    private function buildMysqlDumpCommand(string $outputFile, array $tables = null): string {
        $config = $this->dbConfig;
        $cmd = escapeshellarg($this->findMysqlBinary('mysqldump'));
        $cmd .= ' -h ' . escapeshellarg($config['host'] ?? 'localhost');
        $cmd .= ' -P ' . escapeshellarg((string)($config['port'] ?? 3306));
        $cmd .= ' -u ' . escapeshellarg($config['user'] ?? '');
        if (($config['pass'] ?? '') !== '') {
            $cmd .= ' -p' . escapeshellarg($config['pass']);
        }
        $cmd .= ' --single-transaction --routines --triggers --events';
        $cmd .= ' --hex-blob --skip-lock-tables';
        $cmd .= ' ' . escapeshellarg($config['dbname'] ?? '');
        // 若指定了表，则只导出这些表（用于导入前轻量快照，显著缩短 mysqldump 耗时，避免代理超时）
        if (!empty($tables) && is_array($tables)) {
            foreach ($tables as $t) {
                $cmd .= ' ' . escapeshellarg($t);
            }
        }
        $cmd .= ' > ' . escapeshellarg($outputFile);
        return $cmd;
    }

    /**
     * 构造 mysql 恢复命令
     */
    private function buildMysqlRestoreCommand(string $inputFile): string {
        $config = $this->dbConfig;
        $cmd = escapeshellarg($this->findMysqlBinary('mysql'));
        $cmd .= ' -h ' . escapeshellarg($config['host'] ?? 'localhost');
        $cmd .= ' -P ' . escapeshellarg((string)($config['port'] ?? 3306));
        $cmd .= ' -u ' . escapeshellarg($config['user'] ?? '');
        if (($config['pass'] ?? '') !== '') {
            $cmd .= ' -p' . escapeshellarg($config['pass']);
        }
        $cmd .= ' ' . escapeshellarg($config['dbname'] ?? '');
        $cmd .= ' < ' . escapeshellarg($inputFile);
        return $cmd;
    }

    /**
     * 获取 MySQL 数据库大小（字节）
     */
    private function getMysqlDatabaseSize(): int {
        try {
            $db = get_db();
            $stmt = $db->prepare("SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = :dbname");
            $stmt->execute([':dbname' => $this->dbConfig['dbname'] ?? '']);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * 生成元数据数组
     */
    private function buildMeta(string $filename, string $description, int $size, int $originalSize): array {
        return [
            'filename'        => $filename,
            'description'     => mb_substr($description, 0, 200),
            'size'            => $size,
            'original_size'   => $originalSize,
            'created_at'      => date('Y-m-d H:i:s'),
            'created_at_ts'   => time(),
            'created_by'      => $_SESSION['user_id'] ?? 0,
            'created_by_name' => $_SESSION['username'] ?? t('backup_system_user', '系统'),
            'app_version'     => APP_VERSION,
        ];
    }

    /**
     * 列出所有备份
     *
     * @return array 备份列表（按时间倒序）
     */
    public function listBackups(): array {
        $backups = [];
        // 兼容 Windows（部分环境不支持 GLOB_BRACE），分别匹配两种扩展名再合并
        $sqliteFiles = glob($this->backupDir . 'backup_*.db.gz');
        $mysqlFiles  = glob($this->backupDir . 'backup_*.sql.gz');
        $files = array_merge(
            is_array($sqliteFiles) ? $sqliteFiles : [],
            is_array($mysqlFiles)  ? $mysqlFiles  : []
        );

        foreach ($files as $file) {
            $filename = basename($file);
            $metaFile = $file . '.meta.json';
            $meta = is_file($metaFile) ? (json_decode(@file_get_contents($metaFile), true) ?: []) : [];
            $mtime = filemtime($file);
            $backups[] = [
                'filename'        => $filename,
                'size'            => filesize($file),
                'created_at'      => $meta['created_at'] ?? date('Y-m-d H:i:s', $mtime),
                'created_at_ts'   => $meta['created_at_ts'] ?? $mtime,
                'description'     => $meta['description'] ?? '',
                'created_by_name' => $meta['created_by_name'] ?? t('backup_unknown_user', '未知'),
                'original_size'   => $meta['original_size'] ?? 0,
                'app_version'     => $meta['app_version'] ?? '',
            ];
        }

        usort($backups, function ($a, $b) {
            return $b['created_at_ts'] - $a['created_at_ts'];
        });

        return $backups;
    }

    /**
     * 删除备份
     */
    public function deleteBackup(string $filename): array {
        if (!$this->isValidFilename($filename)) {
            return ['success' => false, 'error' => t('backup_invalid_filename', '无效的备份文件名')];
        }
        $filepath = $this->backupDir . $filename;
        if (!is_file($filepath)) {
            return ['success' => false, 'error' => t('backup_file_not_exists', '备份文件不存在')];
        }
        @unlink($filepath);
        @unlink($filepath . '.meta.json');
        return ['success' => true];
    }

    /**
     * 获取备份文件路径（用于下载）
     */
    public function getBackupPath(string $filename): array {
        if (!$this->isValidFilename($filename)) {
            return ['success' => false, 'error' => t('backup_invalid_filename', '无效的备份文件名')];
        }
        $filepath = $this->backupDir . $filename;
        if (!is_file($filepath)) {
            return ['success' => false, 'error' => t('backup_file_not_exists', '备份文件不存在')];
        }
        return ['success' => true, 'filepath' => $filepath];
    }

    /**
     * 恢复备份
     */
    public function restoreBackup(string $filename): array {
        if (!$this->isValidFilename($filename)) {
            return ['success' => false, 'error' => t('backup_invalid_filename', '无效的备份文件名')];
        }
        $filepath = $this->backupDir . $filename;
        if (!is_file($filepath)) {
            return ['success' => false, 'error' => t('backup_file_not_exists', '备份文件不存在')];
        }

        if ($this->isSqlite()) {
            return $this->restoreSqliteBackup($filepath, $filename);
        }

        if ($this->isMysql()) {
            return $this->restoreMysqlBackup($filepath, $filename);
        }

        return ['success' => false, 'error' => t('backup_unsupported_db_type_short', '不支持的数据库类型')];
    }

    /**
     * SQLite 恢复
     */
    private function restoreSqliteBackup(string $filepath, string $filename): array {
        // 1. 解压备份
        $gzData = @file_get_contents($filepath);
        if ($gzData === false) {
            return ['success' => false, 'error' => t('backup_read_failed', '无法读取备份文件')];
        }
        $data = gzdecode($gzData);
        if ($data === false) {
            return ['success' => false, 'error' => t('backup_corrupted', '备份文件已损坏或格式不正确')];
        }

        $tempDb = $this->backupDir . 'restore_temp_' . date('Ymd_His') . '.db';
        if (@file_put_contents($tempDb, $data) === false) {
            return ['success' => false, 'error' => t('backup_write_temp_failed', '无法写入临时文件')];
        }

        // 2. 验证备份完整性
        $userCount = 0;
        $postCount = 0;
        try {
            $testDb = new PDO('sqlite:' . $tempDb);
            $testDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $tables = $testDb->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('users', $tables, true)) {
                $testDb = null;
                @unlink($tempDb);
                return ['success' => false, 'error' => t('backup_missing_users_table', '备份文件不完整：缺少 users 表')];
            }
            $userCount = (int)$testDb->query("SELECT COUNT(*) FROM users")->fetchColumn();
            if (in_array('posts', $tables, true)) {
                $postCount = (int)$testDb->query("SELECT COUNT(*) FROM posts")->fetchColumn();
            }
            $testDb = null;
        } catch (Exception $e) {
            @unlink($tempDb);
            return ['success' => false, 'error' => t('backup_read_error', '备份文件无法读取：') . $e->getMessage()];
        }

        // 3. 创建恢复前快照
        $preRestoreName = 'prerestore_' . date('Ymd_His') . '.db.gz';
        $preRestorePath = $this->backupDir . $preRestoreName;
        try {
            $db = get_db();
            $db->exec("PRAGMA wal_checkpoint(TRUNCATE)");
        } catch (Exception $e) {
            // 忽略
        }
        $currentData = @file_get_contents($this->dbFile);
        if ($currentData !== false) {
            $gzCurrent = gzencode($currentData, 9);
            if ($gzCurrent !== false) {
                @file_put_contents($preRestorePath, $gzCurrent);
                $preMeta = $this->buildMeta($preRestoreName, t('backup_prerestore_desc', '恢复前自动创建的快照（恢复来源：{file}）', ['file' => $filename]), filesize($preRestorePath), filesize($this->dbFile));
                $preMeta['created_by_name'] = t('backup_prerestore_author', '系统（恢复前快照）');
                @file_put_contents($preRestorePath . '.meta.json', json_encode($preMeta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }

        // 4. 恢复：优先使用 SQLite3::backup 在线恢复
        $restored = false;
        $errorMsg = '';

        $sqlite3Available = class_exists('SQLite3', false) && method_exists('SQLite3', 'backup');
        if ($sqlite3Available) {
            try {
                $source = new SQLite3($tempDb, SQLITE3_OPEN_READONLY);
                $dest = new SQLite3($this->dbFile);
                $source->backup($dest);
                $dest->close();
                $source->close();
                $restored = true;
            } catch (Exception $e) {
                $errorMsg = $e->getMessage();
            }
        }

        // 降级：直接覆盖文件
        if (!$restored) {
            if (!@copy($tempDb, $this->dbFile)) {
                @unlink($tempDb);
                $hint = $errorMsg ? t('backup_error_in_paren', '（{err}）', ['err' => $errorMsg]) : '';
                return ['success' => false, 'error' => t('backup_replace_failed', '无法替换数据库文件，可能被占用') . $hint . t('backup_pause_hint', '。建议暂停站点后重试。')];
            }
        }

        // 5. 清理 WAL/SHM 文件（强制下次连接重建）
        @unlink($this->dbFile . '-wal');
        @unlink($this->dbFile . '-shm');
        @unlink($tempDb);

        return [
            'success'             => true,
            'message'             => t('backup_restore_success', '数据库已成功恢复'),
            'user_count'          => $userCount,
            'post_count'          => $postCount,
            'pre_restore_backup'  => $preRestoreName,
        ];
    }

    /**
     * MySQL 恢复
     */
    private function restoreMysqlBackup(string $filepath, string $filename): array {
        // 1. 解压备份
        $gzData = @file_get_contents($filepath);
        if ($gzData === false) {
            return ['success' => false, 'error' => t('backup_read_failed', '无法读取备份文件')];
        }
        $sql = gzdecode($gzData);
        if ($sql === false) {
            return ['success' => false, 'error' => t('backup_corrupted', '备份文件已损坏或格式不正确')];
        }

        // 1.5 清洗可能混入的命令行工具警告（如旧备份中 mysqldump/mysql 的 stderr 被写入
        // SQL 文件首部）。这些行不是合法 SQL，会导致 mysql 恢复时报 ERROR 1064 语法错误。
        $sql = preg_replace('/^[ \t]*(mysqldump|mysql):[^\n]*\n/m', '', $sql);

        // 2. 简单完整性校验：检查是否包含 CREATE TABLE 或 INSERT
        if (stripos($sql, 'CREATE TABLE') === false && stripos($sql, 'INSERT INTO') === false) {
            return ['success' => false, 'error' => t('backup_sql_incomplete', '备份文件内容不完整，缺少必要的 SQL 语句')];
        }

        $tempSql = $this->backupDir . 'restore_temp_' . date('Ymd_His') . '.sql';
        if (@file_put_contents($tempSql, $sql) === false) {
            return ['success' => false, 'error' => t('backup_write_temp_failed', '无法写入临时文件')];
        }

        // 3. 创建恢复前快照
        $preRestoreName = 'prerestore_' . date('Ymd_His') . '.sql.gz';
        $preRestorePath = $this->backupDir . $preRestoreName;
        $currentSql = $this->createMysqlDumpToString();
        if ($currentSql !== '') {
            $gzCurrent = gzencode($currentSql, 9);
            if ($gzCurrent !== false) {
                @file_put_contents($preRestorePath, $gzCurrent);
                $preMeta = $this->buildMeta($preRestoreName, t('backup_prerestore_desc', '恢复前自动创建的快照（恢复来源：{file}）', ['file' => $filename]), filesize($preRestorePath), $this->getMysqlDatabaseSize());
                $preMeta['created_by_name'] = t('backup_prerestore_author', '系统（恢复前快照）');
                @file_put_contents($preRestorePath . '.meta.json', json_encode($preMeta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }

        // 4. 执行恢复（优先命令行 mysql；找不到或失败时，回退到 PDO 多语句执行）
        $cmdOk = false;
        $cmdError = '';
        $mysqlBin = $this->findMysqlBinary('mysql');
        if (is_file($mysqlBin)) {
            $cmd = $this->buildMysqlRestoreCommand($tempSql);
            $output = [];
            $returnCode = 0;
            @exec($cmd . ' 2>&1', $output, $returnCode);
            if ($returnCode === 0) {
                $cmdOk = true;
            } else {
                $cmdError = implode("\n", $output);
            }
        }

        if (!$cmdOk) {
            // 命令行不可用或失败：使用 PDO 多语句兜底
            $pdoResult = $this->restoreMysqlBackupViaPdo($sql);
            if ($pdoResult['success']) {
                @unlink($tempSql);
                return [
                    'success'             => true,
                    'message'             => t('backup_restore_success', '数据库已成功恢复'),
                    'user_count'          => 0,
                    'post_count'          => 0,
                    'pre_restore_backup'  => $preRestoreName,
                    'note'                => t('backup_pdo_fallback_used', '（通过 PDO 恢复，命令行 mysql 不可用或被禁用）'),
                ];
            }
            @unlink($tempSql);
            $error = $cmdError ? $cmdError : $pdoResult['error'];
            return ['success' => false, 'error' => t('backup_mysql_restore_failed', 'mysql 恢复命令执行失败') . ($error ? t('backup_error_suffix', '：') . $error : '') . ' (' . $mysqlBin . ')'];
        }

        @unlink($tempSql);

        return [
            'success'             => true,
            'message'             => t('backup_restore_success', '数据库已成功恢复'),
            'user_count'          => 0,
            'post_count'          => 0,
            'pre_restore_backup'  => $preRestoreName,
        ];
    }

    /**
     * 通过 PDO 多语句执行恢复（命令行 mysql 不可用时的兜底方案）
     */
    private function restoreMysqlBackupViaPdo(string $sql): array {
        try {
            $config = $this->dbConfig;
            $host = $config['host'] ?? 'localhost';
            $port = $config['port'] ?? 3306;
            $dbname = $config['dbname'] ?? '';
            $dsn = 'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4';
            if ($dbname !== '') {
                $dsn .= ';dbname=' . $dbname;
            }
            $pdo = new PDO($dsn, $config['user'] ?? '', $config['pass'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
            ]);
            // 一次性执行整个 SQL（mysqldump 生成的脚本不含用户可控参数，多语句风险可接受）
            $pdo->exec($sql);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 将当前 MySQL 数据库导出为 SQL 字符串（用于恢复前快照）
     */
    private function createMysqlDumpToString(): string {
        // exec 被禁用：回退纯 PHP 导出为字符串（恢复前快照）
        if (!$this->canExec()) {
            $res = $this->mysqlDumpToSqlViaPdo();
            return $res['ok'] ? $res['sql'] : '';
        }
        $tempFile = $this->backupDir . 'snapshot_temp_' . date('Ymd_His') . '.sql';
        $errFile = $tempFile . '.err';
        // stderr 重定向到独立文件，避免污染 SQL 字符串（原理同 createMysqlBackup）
        $cmd = $this->buildMysqlDumpCommand($tempFile) . ' 2> ' . escapeshellarg($errFile);
        $output = [];
        $returnCode = 0;
        @exec($cmd, $output, $returnCode);
        @unlink($errFile);
        if ($returnCode !== 0 || !is_file($tempFile)) {
            @unlink($tempFile);
            return '';
        }
        $sql = @file_get_contents($tempFile);
        @unlink($tempFile);
        return $sql !== false ? $sql : '';
    }

    /**
     * 获取备份统计信息
     */
    public function getStats(): array {
        $backups = $this->listBackups();
        $totalSize = 0;
        foreach ($backups as $b) {
            $totalSize += $b['size'];
        }

        if ($this->isSqlite()) {
            return [
                'count'           => count($backups),
                'total_size'      => $totalSize,
                'db_size'         => is_file($this->dbFile) ? filesize($this->dbFile) : 0,
                'wal_size'        => is_file($this->dbFile . '-wal') ? filesize($this->dbFile . '-wal') : 0,
                'last_backup'     => !empty($backups) ? $backups[0]['created_at'] : null,
                'last_backup_ts'  => !empty($backups) ? $backups[0]['created_at_ts'] : 0,
            ];
        }

        // MySQL
        return [
            'count'           => count($backups),
            'total_size'      => $totalSize,
            'db_size'         => $this->getMysqlDatabaseSize(),
            'wal_size'        => 0,
            'last_backup'     => !empty($backups) ? $backups[0]['created_at'] : null,
            'last_backup_ts'  => !empty($backups) ? $backups[0]['created_at_ts'] : 0,
        ];
    }

    /**
     * 验证文件名格式（防路径遍历攻击）
     */
    private function isValidFilename(string $filename): bool {
        return (bool)preg_match('/^backup_\d{8}_\d{6}\.(db|sql)\.gz$/', $filename)
            || (bool)preg_match('/^prerestore_\d{8}_\d{6}\.(db|sql)\.gz$/', $filename);
    }

    /**
     * 自动清理旧备份（保留最近 N 个）
     */
    private function autoCleanup(int $keepCount): void {
        $backups = $this->listBackups();
        if (count($backups) <= $keepCount) {
            return;
        }
        $toDelete = array_slice($backups, $keepCount);
        foreach ($toDelete as $b) {
            @unlink($this->backupDir . $b['filename']);
            @unlink($this->backupDir . $b['filename'] . '.meta.json');
        }
    }

    /**
     * 获取自动备份配置
     */
    public function getAutoBackupConfig(): array {
        $configFile = $this->backupDir . 'auto_backup_config.json';
        $defaults = [
            'enabled'   => false,
            'interval'  => 24,      // 默认 24 小时
            'keep'      => 10,      // 保留最近 10 个自动备份
            'last_run'  => 0,
        ];
        if (is_file($configFile)) {
            $data = json_decode((string)@file_get_contents($configFile), true);
            if (is_array($data)) {
                return array_merge($defaults, $data);
            }
        }
        return $defaults;
    }

    /**
     * 保存自动备份配置
     */
    public function saveAutoBackupConfig(array $config): bool {
        $configFile = $this->backupDir . 'auto_backup_config.json';
        $data = [
            'enabled'   => (bool)($config['enabled'] ?? false),
            'interval'  => max(1, (int)($config['interval'] ?? 24)),
            'keep'      => max(1, (int)($config['keep'] ?? 10)),
            'last_run'  => (int)($config['last_run'] ?? 0),
        ];
        return @file_put_contents($configFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
    }

    /**
     * 检查并执行定时自动备份（伪 Cron）
     */
    public function tryAutoBackup(): ?array {
        $config = $this->getAutoBackupConfig();
        if (!$config['enabled']) return null;

        $now = time();
        $elapsed = $now - $config['last_run'];
        if ($elapsed < $config['interval'] * 3600) return null;

        $result = $this->createBackup(t('backup_auto_label', '【自动备份】每 {hours} 小时', ['hours' => $config['interval']]));

        if ($result['success']) {
            $config['last_run'] = $now;
            $this->saveAutoBackupConfig($config);

            $backups = $this->listBackups();
            $autoBackups = array_filter($backups, function ($b) {
                return strpos($b['description'] ?? '', t('backup_auto_prefix', '【自动备份】')) === 0;
            });
            if (count($autoBackups) > $config['keep']) {
                $toDelete = array_slice($autoBackups, $config['keep']);
                foreach ($toDelete as $b) {
                    @unlink($this->backupDir . $b['filename']);
                    @unlink($this->backupDir . $b['filename'] . '.meta.json');
                }
            }
        }

        return $result;
    }
}
