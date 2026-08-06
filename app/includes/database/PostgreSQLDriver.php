<?php
/**
 * 云界论坛 - PostgreSQL 数据库驱动
 */

require_once __DIR__ . '/AbstractDriver.php';

class PostgreSQLDriver extends AbstractDriver
{
    protected function createConnection(): PDO
    {
        $host   = $this->config['host'] ?? 'localhost';
        $port   = $this->config['port'] ?? '5432';
        $dbname = $this->config['dbname'] ?? 'forum';
        $user   = $this->config['user'] ?? 'postgres';
        $pass   = $this->config['pass'] ?? '';

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
        return new PDO($dsn, $user, $pass);
    }

    protected function initConnection(): void
    {
        $this->pdo->exec("SET NAMES 'UTF8'");
    }

    public function now(): string
    {
        return 'NOW()';
    }

    public function minutesAgo(int $minutes): string
    {
        return "NOW() - INTERVAL '{$minutes} minutes'";
    }

    public function daysAgo(int $days): string
    {
        return "NOW() - INTERVAL '{$days} days'";
    }

    public function groupByHour(string $column, int $utcOffset = 8): string
    {
        return "EXTRACT(HOUR FROM {$column} + INTERVAL '{$utcOffset} hours')";
    }

    public function getTables(): array
    {
        $rows = $this->pdo->query(
            "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public'"
        )->fetchAll(PDO::FETCH_COLUMN);
        return $rows ?: [];
    }

    public function getTableInfo(string $tableName): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT column_name AS name, data_type AS type,
                    is_nullable AS notnull, column_default AS dflt_value
             FROM information_schema.columns
             WHERE table_name = :table
             ORDER BY ordinal_position"
        );
        $stmt->execute([':table' => $tableName]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'name'      => $row['name'],
                'type'      => $row['type'],
                'notnull'   => ($row['notnull'] === 'NO') ? 1 : 0,
                'dflt_value'=> $row['dflt_value'],
                'pk'        => 0, // 需要额外查询约束
            ];
        }
        return $result;
    }

    public function insertIgnoreClause(): string
    {
        return 'INSERT INTO';
    }

    public function replaceClause(): string
    {
        return 'INSERT INTO';
    }

    public function autoIncrementKeyword(): string
    {
        return 'GENERATED ALWAYS AS IDENTITY';
    }

    public function mapColumnType(string $type): string
    {
        switch ($type) {
            case 'int':      return 'INTEGER';
            case 'text':     return 'TEXT';
            case 'datetime': return 'TIMESTAMP';
            case 'bool':     return 'BOOLEAN';
            default:         return $type;
        }
    }

    public function primaryKeyDef(string $column = 'id'): string
    {
        return "{$column} {$this->mapColumnType('int')} PRIMARY KEY {$this->autoIncrementKeyword()}";
    }

    public function translateDDL(string $sql): string
    {
        $sql = str_replace('INTEGER PRIMARY KEY AUTOINCREMENT', 'SERIAL PRIMARY KEY', $sql);
        $sql = str_replace('AUTOINCREMENT', '', $sql);
        $sql = str_replace('UPDATE OR IGNORE ', 'UPDATE ', $sql);
        // DATETIME → TIMESTAMP（PostgreSQL 无 DATETIME 类型）
        $sql = preg_replace('/\bDATETIME\b/i', 'TIMESTAMP', $sql);

        // INSERT OR IGNORE → INSERT INTO ... ON CONFLICT DO NOTHING
        //（与 translateSQL 保持一致的冲突处理）
        if (preg_match('/^INSERT OR IGNORE INTO /i', $sql)) {
            $sql = preg_replace('/^INSERT OR IGNORE INTO /i', 'INSERT INTO ', $sql);
            if (stripos($sql, 'ON CONFLICT') === false) {
                $sql = rtrim($sql, ';') . ' ON CONFLICT DO NOTHING';
            }
            return $sql;
        }

        // PostgreSQL 9.5+ 支持 CREATE INDEX IF NOT EXISTS，保持不动
        return $sql;
    }

    public function randomOrderFunc(): string
    {
        return 'RANDOM()';
    }

    public function dateColExpr(string $col, string $modifier = ''): string
    {
        if ($modifier === '') {
            return "{$col}::date";
        }
        if (preg_match('/^([+-]?\d+)\s*hours?$/', $modifier, $m)) {
            return "({$col} + INTERVAL '{$m[1]} hours')::date";
        }
        return "{$col}::date";
    }

    public function curDateExpr(): string
    {
        return 'CURRENT_DATE';
    }

    public function upsertConflictClause(string $conflictColumns): string
    {
        return "ON CONFLICT({$conflictColumns}) DO UPDATE SET";
    }

    /**
     * 覆盖 translateSQL：为 INSERT OR IGNORE/REPLACE 附加 ON CONFLICT 子句
     */
    public function translateSQL(string $sql): string
    {
        // INSERT OR IGNORE → INSERT INTO ... ON CONFLICT DO NOTHING
        if (preg_match('/^INSERT OR IGNORE INTO /i', $sql)) {
            $sql = preg_replace('/^INSERT OR IGNORE INTO /i', 'INSERT INTO ', $sql);
            if (stripos($sql, 'ON CONFLICT') === false) {
                $sql = rtrim($sql, ';') . ' ON CONFLICT DO NOTHING';
            }
            return $sql;
        }
        // INSERT OR REPLACE → INSERT INTO ... ON CONFLICT (pk) DO UPDATE SET ...
        // 注意: 需要主键列名，此处做最佳努力处理
        if (preg_match('/^INSERT OR REPLACE INTO /i', $sql)) {
            $sql = preg_replace('/^INSERT OR REPLACE INTO /i', 'INSERT INTO ', $sql);
            return $sql;
        }
        return $sql;
    }
}
