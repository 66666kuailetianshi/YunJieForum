<?php
/**
 * 云界论坛 - 数据库抽象驱动基类
 * 封装 PDO，提供跨数据库兼容的查询辅助方法
 */

abstract class AbstractDriver
{
    /** @var PDO */
    protected $pdo;
    /** @var string */
    protected $dbName;
    /** @var array */
    protected $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->dbName = $config['dbname'] ?? '';
        $this->pdo = $this->createConnection();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->initConnection();
    }

    /**
     * 创建原始 PDO 连接（由各驱动实现）
     */
    abstract protected function createConnection(): PDO;

    /**
     * 驱动特定的初始化（PRAGMA / SET NAMES 等）
     */
    protected function initConnection(): void
    {
        // no-op in base
    }

    /**
     * 获取原始 PDO 对象（向后兼容，现有代码直接使用 PDO）
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * 重连数据库（当持久连接被 MySQL 超时断开时调用）
     */
    public function reconnect(): void
    {
        $this->pdo = $this->createConnection();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->initConnection();
    }

    // ==================== 查询辅助方法 ====================

    /**
     * 获取当前日期时间表达式的 SQL（可移植）
     * MySQL: NOW()
     * SQLite: datetime('now')
     * PostgreSQL: NOW()
     */
    public function now(): string
    {
        return "datetime('now')";
    }

    /**
     * 获取 N 分钟前的表达式
     */
    public function minutesAgo(int $minutes): string
    {
        return "datetime('now', '-{$minutes} minutes')";
    }

    /**
     * 获取 N 天前的表达式
     */
    public function daysAgo(int $days): string
    {
        return "datetime('now', '-{$days} days')";
    }

    /**
     * 按小时分组的表达式
     * @param string $column      日期时间列名
     * @param int    $utcOffset   时区偏移小时数
     */
    public function groupByHour(string $column, int $utcOffset = 8): string
    {
        return "strftime('%H', {$column}, '+{$utcOffset} hours')";
    }

    /**
     * 获取数据库所有用户表的名称
     */
    abstract public function getTables(): array;

    /**
     * 获取某个表的列信息
     * @return array [{name, type, notnull, dflt_value, pk}, ...]
     */
    abstract public function getTableInfo(string $tableName): array;

    /**
     * 获取自增 ID 对应的列名
     */
    public function lastInsertIdName(?string $tableName = null): string
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * INSERT OR IGNORE 可移植写法
     * MySQL: INSERT IGNORE INTO
     * SQLite: INSERT OR IGNORE INTO
     * PostgreSQL: INSERT ... ON CONFLICT DO NOTHING
     *
     * @return string SQL 片段
     */
    public function insertIgnoreClause(): string
    {
        return 'INSERT OR IGNORE INTO';
    }

    /**
     * REPLACE / INSERT OR REPLACE 可移植写法
     * MySQL: REPLACE INTO
     * SQLite: INSERT OR REPLACE INTO
     *
     * @return string SQL 片段
     */
    public function replaceClause(): string
    {
        return 'INSERT OR REPLACE INTO';
    }

    /**
     * 获取自增主键的 SQL 关键字
     * MySQL/SQLite: AUTOINCREMENT / AUTO_INCREMENT
     * PostgreSQL: SERIAL（但这里用 GENERATED ALWAYS AS IDENTITY）
     */
    public function autoIncrementKeyword(): string
    {
        return 'AUTOINCREMENT';
    }

    /**
     * 获取表创建时的类型映射
     * @param string $type 通用类型名 (int, text, datetime, bool)
     * @return string 数据库原生类型
     */
    public function mapColumnType(string $type): string
    {
        switch ($type) {
            case 'int':      return 'INTEGER';
            case 'text':     return 'TEXT';
            case 'datetime': return 'DATETIME';
            case 'bool':     return 'INTEGER';
            default:         return $type;
        }
    }

    /**
     * 获取主键定义的 SQL
     */
    public function primaryKeyDef(string $column = 'id'): string
    {
        return "{$column} {$this->mapColumnType('int')} PRIMARY KEY {$this->autoIncrementKeyword()}";
    }

    /**
     * 判断是否使用文件数据库（备份管理判断）
     */
    public function isFileBased(): bool
    {
        return false;
    }

    /**
     * 获取数据库文件路径（仅文件数据库有）
     */
    public function getDbFile(): string
    {
        return '';
    }

    /**
     * 将 SQLite 风格的 DDL 翻译为当前数据库方言
     * 各子类可覆盖以提供特定翻译
     */
    public function translateDDL(string $sql): string
    {
        return $sql;
    }

    /**
     * 随机排序函数名
     * SQLite/PostgreSQL: RANDOM()  MySQL: RAND()
     */
    public function randomOrderFunc(): string
    {
        return 'RANDOM()';
    }

    /**
     * 标识符（表名/列名）引用
     * SQLite/PostgreSQL: 双引号；MySQL 默认模式双引号是字符串字面量，需用反引号
     */
    public function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /**
     * 取日期部分的 SQL 表达式
     * @param string $col      列名
     * @param string $modifier 可选修饰符，如 '+8 hours'
     */
    public function dateColExpr(string $col, string $modifier = ''): string
    {
        return $modifier ? "date({$col}, '{$modifier}')" : "date({$col})";
    }

    /**
     * 当前日期 SQL 表达式
     */
    public function curDateExpr(): string
    {
        return "date('now')";
    }

    /**
     * 翻译应用层 DML SQL（INSERT OR REPLACE/IGNORE 等）
     * 与 translateDDL 区分：DDL 用于建表，此方法用于业务查询
     */
    public function translateSQL(string $sql): string
    {
        $sql = preg_replace('/^INSERT OR REPLACE INTO /i', $this->replaceClause() . ' ', $sql);
        $sql = preg_replace('/^INSERT OR IGNORE INTO /i', $this->insertIgnoreClause() . ' ', $sql);
        return $sql;
    }

    /**
     * UPSERT 冲突子句（INSERT ... ON CONFLICT / ON DUPLICATE KEY UPDATE）
     * @param string $conflictColumns 冲突检测列，如 "visit_date, ip_hash"
     */
    public function upsertConflictClause(string $conflictColumns): string
    {
        return "ON CONFLICT({$conflictColumns}) DO UPDATE SET";
    }
}
