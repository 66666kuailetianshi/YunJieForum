<?php
/**
 * 云界论坛 - SQLite 数据库驱动
 */

require_once __DIR__ . '/AbstractDriver.php';

class SQLiteDriver extends AbstractDriver
{
    protected function createConnection(): PDO
    {
        $dbFile = $this->config['file'] ?? (DATA_PATH . 'forum.db');
        $dir = dirname($dbFile);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new Exception(t('db_mkdir_failed', '无法创建数据目录：{dir}', ['dir' => $dir]));
            }
        }
        return new PDO('sqlite:' . $dbFile);
    }

    protected function initConnection(): void
    {
        $this->pdo->exec("PRAGMA foreign_keys = ON;");
        try {
            $this->pdo->exec("PRAGMA journal_mode = WAL;");
            $this->pdo->exec("PRAGMA synchronous = NORMAL;");
            $this->pdo->exec("PRAGMA cache_size = 10000;");
            $this->pdo->exec("PRAGMA temp_store = MEMORY;");
        } catch (Exception $e) {
            // 忽略权限不足导致的 PRAGMA 失败
        }
    }

    public function getTables(): array
    {
        $rows = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
            ->fetchAll(PDO::FETCH_COLUMN);
        return $rows ?: [];
    }

    public function getTableInfo(string $tableName): array
    {
        // PRAGMA 不支持参数绑定，需要内联表名
        $safeName = str_replace("'", "''", $tableName);
        return $this->pdo->query("PRAGMA table_info('{$safeName}')")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function isFileBased(): bool
    {
        return true;
    }

    public function getDbFile(): string
    {
        return $this->config['file'] ?? (DATA_PATH . 'forum.db');
    }
}
