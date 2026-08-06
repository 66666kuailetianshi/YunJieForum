<?php
/**
 * 云界论坛 - 数据库工厂
 * 根据配置创建对应的数据库驱动
 */

require_once __DIR__ . '/SQLiteDriver.php';
require_once __DIR__ . '/MySQLDriver.php';
require_once __DIR__ . '/PostgreSQLDriver.php';

class DatabaseFactory
{
    /**
     * 支持的数据库类型
     */
    public static function supportedTypes(): array
    {
        return ['sqlite', 'mysql', 'pgsql'];
    }

    /**
     * 创建数据库驱动实例
     *
     * @param array $config [
     *   'type'   => 'sqlite'|'mysql'|'pgsql',
     *   'file'   => '/path/to/db',     // SQLite only
     *   'host'   => 'localhost',        // MySQL/PostgreSQL
     *   'port'   => '3306',            // MySQL/PostgreSQL
     *   'dbname' => 'forum',           // MySQL/PostgreSQL
     *   'user'   => 'root',            // MySQL/PostgreSQL
     *   'pass'   => '',                // MySQL/PostgreSQL
     * ]
     * @return AbstractDriver
     * @throws Exception
     */
    public static function create(array $config): AbstractDriver
    {
        $type = $config['type'] ?? 'sqlite';
        $type = strtolower($type);

        switch ($type) {
            case 'sqlite':
                return new SQLiteDriver($config);
            case 'mysql':
                return new MySQLDriver($config);
            case 'pgsql':
                return new PostgreSQLDriver($config);
            default:
                throw new Exception(t('db_unsupported_type', '不支持的数据库类型：{type}（支持 sqlite / mysql / pgsql）', ['type' => $type]));
        }
    }

    /**
     * 测试数据库连接
     *
     * @param array $config 数据库配置
     * @return array ['success' => bool, 'error' => string|null]
     */
    public static function test(array $config): array
    {
        try {
            $driver = self::create($config);
            $driver->pdo()->exec('SELECT 1');
            return ['success' => true, 'error' => null];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 从站点配置文件加载数据库配置
     */
    public static function loadConfig(): array
    {
        $config = [
            'type' => defined('DB_TYPE') ? DB_TYPE : 'sqlite',
        ];

        if ($config['type'] === 'sqlite') {
            $config['file'] = defined('DB_FILE') ? DB_FILE : (DATA_PATH . 'forum.db');
        } else {
            $config['host']   = defined('DB_HOST') ? DB_HOST : 'localhost';
            $config['port']   = defined('DB_PORT') ? DB_PORT : ($config['type'] === 'mysql' ? '3306' : '5432');
            $config['dbname'] = defined('DB_NAME') ? DB_NAME : 'forum';
            $config['user']   = defined('DB_USER') ? DB_USER : '';
            $config['pass']   = defined('DB_PASS') ? DB_PASS : '';
        }

        return $config;
    }
}
