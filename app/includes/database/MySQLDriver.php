<?php
/**
 * 云界论坛 - MySQL 数据库驱动
 */

require_once __DIR__ . '/AbstractDriver.php';

class MySQLDriver extends AbstractDriver
{
    protected function createConnection(): PDO
    {
        $host   = $this->config['host'] ?? 'localhost';
        $port   = $this->config['port'] ?? '3306';
        $dbname = $this->config['dbname'] ?? 'forum';
        $user   = $this->config['user'] ?? 'root';
        $pass   = $this->config['pass'] ?? '';

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
        // 持久连接：PHP-FPM worker 间复用连接，显著减少 MySQL 握手开销
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_PERSISTENT      => true,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4'",
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    protected function initConnection(): void
    {
        $this->pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,ONLY_FULL_GROUP_BY'");
        $this->pdo->exec("SET time_zone = '+00:00'");
        $this->ensureStorageAndCharset();
    }

    /**
     * 自动修复存储引擎与字符集：
     * 1) MyISAM 索引上限 1000 字节，utf8mb4 的 VARCHAR(255) 索引需 1020 字节（报 1071），
     *    且 MyISAM 不支持外键，统一转换为 InnoDB；
     * 2) 数据库/表为 utf8（utf8_general_ci）而连接为 utf8mb4 时，
     *    字符串比较会报 1267 Illegal mix of collations，统一转换为 utf8mb4。
     * 单表失败或权限不足时静默忽略，不影响连接与其他表。
     */
    private function ensureStorageAndCharset(): void
    {
        // 跨请求缓存：24 小时内只检查一次，避免每个连接都查询 information_schema
        //（持久连接下每个 worker 首次连接才执行一次）
        try {
            $cacheFile = DATA_PATH . 'mysql_charset_check.lock';
            if (is_file($cacheFile)) {
                $cached = (int)@file_get_contents($cacheFile);
                if (time() - $cached < 86400) {
                    return;
                }
            }
        } catch (\Throwable $ignored) {}

        try {
            $dbNotUtf8mb4 = (int)$this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.SCHEMATA
                 WHERE schema_name = DATABASE() AND default_character_set_name <> 'utf8mb4'"
            )->fetchColumn();
            if ($dbNotUtf8mb4 > 0) {
                $dbName = (string)$this->pdo->query('SELECT DATABASE()')->fetchColumn();
                $dbName = str_replace('`', '``', $dbName);
                $this->pdo->exec("ALTER DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
            $tables = $this->pdo->query(
                "SELECT table_name, engine, table_collation FROM information_schema.TABLES
                 WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
            )->fetchAll(PDO::FETCH_ASSOC);
            // 转换期间临时关闭外键检查：否则子表已有 FK 或父表尚未转换时
            // ALTER 会因外键约束失败（1215），导致部分表永远转不过来
            $this->pdo->exec('SET foreign_key_checks = 0');
            try {
                foreach ($tables as $t) {
                    $needEngine  = strtoupper((string)($t['engine'] ?? '')) !== 'INNODB';
                    $needCharset = empty($t['table_collation']) || stripos($t['table_collation'], 'utf8mb4') !== 0;
                    if (!$needEngine && !$needCharset) {
                        continue;
                    }
                    $alters = [];
                    if ($needEngine) {
                        $alters[] = 'ENGINE=InnoDB';
                    }
                    if ($needCharset) {
                        $alters[] = 'CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
                    }
                    $table = str_replace('`', '``', $t['table_name']);
                    try {
                        $this->pdo->exec("ALTER TABLE `{$table}` " . implode(', ', $alters));
                    } catch (\Throwable $e) {
                        // 单表修复失败不影响其他表
                    }
                }
            } finally {
                $this->pdo->exec('SET foreign_key_checks = 1');
            }
        } catch (\Throwable $e) {
            // 权限不足等情况忽略，避免影响正常使用
            error_log('MySQLDriver::ensureStorageAndCharset failed: ' . get_class($e));
        }
        // 无论成功与否都记录检查时间，避免每次连接重复执行
        try {
            @file_put_contents(DATA_PATH . 'mysql_charset_check.lock', (string)time(), LOCK_EX);
        } catch (\Throwable $ignored) {}
    }

    public function now(): string
    {
        return 'NOW()';
    }

    public function minutesAgo(int $minutes): string
    {
        return "DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)";
    }

    public function daysAgo(int $days): string
    {
        return "DATE_SUB(NOW(), INTERVAL {$days} DAY)";
    }

    public function groupByHour(string $column, int $utcOffset = 8): string
    {
        return "HOUR(DATE_ADD({$column}, INTERVAL {$utcOffset} HOUR))";
    }

    public function getTables(): array
    {
        $rows = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        return $rows ?: [];
    }

    /**
     * MySQL 标识符引用：默认模式下双引号是字符串字面量，必须用反引号
     */
    public function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function getTableInfo(string $tableName): array
    {
        $stmt = $this->pdo->prepare('DESCRIBE `' . str_replace('`', '``', $tableName) . '`');
        $stmt->execute();
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'name'      => $row['Field'],
                'type'      => $row['Type'],
                'notnull'   => (int)($row['Null'] === 'NO'),
                'dflt_value'=> $row['Default'],
                'pk'        => (int)($row['Key'] === 'PRI'),
            ];
        }
        return $result;
    }

    public function insertIgnoreClause(): string
    {
        return 'INSERT IGNORE INTO';
    }

    public function replaceClause(): string
    {
        return 'REPLACE INTO';
    }

    public function autoIncrementKeyword(): string
    {
        return 'AUTO_INCREMENT';
    }

    public function mapColumnType(string $type): string
    {
        switch ($type) {
            case 'int':      return 'INT';
            case 'text':     return 'TEXT';
            case 'datetime': return 'DATETIME';
            case 'bool':     return 'TINYINT(1)';
            default:         return $type;
        }
    }

    public function translateDDL(string $sql): string
    {
        $sql = preg_replace('/INTEGER\s+PRIMARY\s+KEY\s+AUTOINCREMENT/i', 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY', $sql);
        $sql = str_replace('AUTOINCREMENT', 'AUTO_INCREMENT', $sql);
        $sql = str_replace('INSERT OR IGNORE INTO', 'INSERT IGNORE INTO', $sql);
        $sql = str_replace('UPDATE OR IGNORE ', 'UPDATE ', $sql);
        // MySQL 不支持 CREATE INDEX IF NOT EXISTS，直接去掉
        $sql = preg_replace('/CREATE INDEX IF NOT EXISTS\s+/i', 'CREATE INDEX ', $sql);
        // MySQL 不支持部分索引 WHERE 子句
        $sql = preg_replace('/(CREATE\s+INDEX\s+\S+\s+ON\s+\S+\s*\([^)]*(?:\([^)]*\)[^)]*)*\))\s+WHERE\s+.+/i', '$1', $sql);
        // MySQL 5.7 TEXT/BLOB/JSON 列不能有默认值，移除 DEFAULT ''
        $sql = preg_replace("/TEXT( NOT NULL)? DEFAULT ''/", 'TEXT$1', $sql);
        // CREATE TABLE 显式指定 InnoDB + utf8mb4：
        // 避免继承服务器默认的 MyISAM（索引 1000 字节上限报 1071、不支持外键）
        // 或数据库默认的 utf8 字符集（报 1267 排序规则冲突）
        if (preg_match('/^\s*CREATE\s+TABLE/i', $sql) && stripos($sql, 'ENGINE') === false) {
            $sql = rtrim($sql) . ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }
        return $sql;
    }

    public function randomOrderFunc(): string
    {
        return 'RAND()';
    }

    public function dateColExpr(string $col, string $modifier = ''): string
    {
        if ($modifier === '') {
            return "DATE({$col})";
        }
        // SQLite '+8 hours' → MySQL INTERVAL 8 HOUR
        if (preg_match('/^([+-]?\d+)\s*hours?$/', $modifier, $m)) {
            $sign = $m[1] >= 0 ? '+' : '';
            return "DATE(DATE_ADD({$col}, INTERVAL {$m[1]} HOUR))";
        }
        return "DATE({$col})";
    }

    public function curDateExpr(): string
    {
        return 'CURDATE()';
    }

    public function upsertConflictClause(string $conflictColumns): string
    {
        return 'ON DUPLICATE KEY UPDATE';
    }
}
