<?php
/**
 * 云界论坛 - 数据库连接（统一入口）
 * 通过 DatabaseFactory 创建数据库驱动，自动适配 SQLite / MySQL / PostgreSQL
 */

require_once APP_ROOT . 'app/includes/config.php';
require_once APP_ROOT . 'app/includes/database/DatabaseFactory.php';

/**
 * 获取数据库驱动实例（单例）
 * 自动检测并重连被 MySQL 超时断开的持久连接
 */
function get_db_driver(): AbstractDriver {
    static $driver = null;
    static $lastPing = 0;
    if ($driver !== null) {
        // 每 5 秒最多探活一次，避免每个请求都执行 SELECT 1
        $now = time();
        if ($now - $lastPing >= 5) {
            try {
                $driver->pdo()->query('SELECT 1');
            } catch (\PDOException $e) {
                if (stripos($e->getMessage(), 'gone away') !== false) {
                    $driver->reconnect();
                } else {
                    error_log('数据库探活异常: ' . $e->getMessage());
                }
            } catch (\Throwable $ignored) {}
            $lastPing = $now;
        }
        return $driver;
    }
    $config = DatabaseFactory::loadConfig();
    $driver = DatabaseFactory::create($config);
    $lastPing = time();
    return $driver;
}

/**
 * 获取 PDO 连接（单例，向后兼容，推荐使用 get_db_driver()）
 * 自动处理 MySQL 持久连接超时后的重连（"MySQL server has gone away"）
 */
function get_db(): PDO {
    $driver = get_db_driver();
    try {
        // 连接探活已由 get_db_driver() 内置的 5 秒节流探活 + reconnect 覆盖
        // （含 "MySQL server has gone away" 恢复），此处不再无条件执行 SELECT 1，
        // 避免同一请求内 5-10 次 get_db() 调用各自重复探活。
        $db = $driver->pdo();
    } catch (\Throwable $e) {
        // 首次连接失败时也尝试重连（兜底）
        try {
            $driver->reconnect();
        } catch (\Throwable $ignored) {
            throw $e;
        }
        $db = $driver->pdo();
    }
    auto_migrate();
    return $db;
}

/**
 * 执行 DDL 语句（自动翻译 SQLite 语法为当前数据库方言）
 */
function ddl_exec(string $sql): void {
    $db = get_db_driver()->pdo();
    $translated = get_db_driver()->translateDDL($sql);
    $start = microtime(true);
    try {
        $db->exec($translated);
        ddl_install_log('ok', $sql, $translated, microtime(true) - $start);
    } catch (\PDOException $e) {
        $msg = $e->getMessage();
        // 优先使用 SQLSTATE 判断可忽略的重复错误，字符串匹配作为后备
        $sqlState = $e->getCode();
        $errInfo = $e->errorInfo;
        $mysqlErrno = isset($errInfo[1]) ? (int)$errInfo[1] : 0;
        $isSkippable = false;
        // SQLSTATE 判断
        if ($sqlState === '42S01' || $mysqlErrno === 1050) {                 // 表已存在
            $isSkippable = true;
        } elseif ($sqlState === '42S21' || $mysqlErrno === 1060) {          // 重复列名
            $isSkippable = true;
        } elseif ($sqlState === '42000' && $mysqlErrno === 1061) {          // 重复索引名
            $isSkippable = true;
        } elseif ($sqlState === '23000' && $mysqlErrno === 1062) {          // 重复行（UNIQUE 冲突）
            $isSkippable = true;
        }
        // 字符串匹配作为后备（MySQL 不同版本/语言环境兼容）
        if (!$isSkippable) {
            if (stripos($msg, 'Duplicate key name') !== false ||
                stripos($msg, 'already exists') !== false ||
                stripos($msg, 'Duplicate column name') !== false) {
                $isSkippable = true;
            }
        }
        if ($isSkippable) {
            ddl_install_log('skipped', $sql, $translated, microtime(true) - $start, $msg);
            return;
        }
        ddl_install_log('error', $sql, $translated, microtime(true) - $start, $msg);
        throw $e;
    }
}

/**
 * 准备 DDL 语句（自动翻译 SQLite 语法）
 */
function ddl_prepare(PDO $db, string $sql): PDOStatement {
    return $db->prepare(get_db_driver()->translateDDL($sql));
}

/**
 * 准备 DML SQL（自动翻译 INSERT OR REPLACE/IGNORE）
 */
function sql_prepare(PDO $db, string $sql): PDOStatement {
    return $db->prepare(get_db_driver()->translateSQL($sql));
}

/**
 * 确保核心父表存在（用户、版块分类、版块、帖子、回复）
 *
 * auto_migrate() 在 init_db() 之前执行，而 reports 等表带有指向
 * users/posts/replies 的外键。全新安装时父表尚未创建，
 * 必须先建好父表，否则报 1215 Cannot add foreign key constraint。
 * 已存在的表则跳过（CREATE TABLE IF NOT EXISTS 幂等，不影响存量数据）。
 */
function ensure_core_tables(PDO $db): void {
    ddl_exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid INTEGER UNIQUE DEFAULT NULL,
        username VARCHAR(32) UNIQUE NOT NULL,
        email VARCHAR(128) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        avatar VARCHAR(255) DEFAULT NULL,
        signature VARCHAR(255) DEFAULT '',
        points INTEGER DEFAULT 0,
        coins INTEGER DEFAULT 0,
        posts_count INTEGER DEFAULT 0,
        role VARCHAR(20) DEFAULT 'user',
        last_checkin DATE DEFAULT NULL,
        checkin_streak INTEGER DEFAULT 0,
        remember_token VARCHAR(255) DEFAULT NULL,
        last_active DATETIME DEFAULT NULL,
        status VARCHAR(20) DEFAULT 'active',
        banned_until DATETIME DEFAULT NULL,
        muted_until DATETIME DEFAULT NULL,
        status_reason TEXT DEFAULT '',
        login_fails INTEGER DEFAULT 0,
        login_locked_until DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    ddl_exec("CREATE TABLE IF NOT EXISTS forum_categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(100) NOT NULL,
        display_order INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    ddl_exec("CREATE TABLE IF NOT EXISTS forums (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER NOT NULL,
        name VARCHAR(100) NOT NULL,
        description TEXT DEFAULT '',
        icon VARCHAR(50) DEFAULT 'folder',
        display_order INTEGER DEFAULT 0,
        threads_count INTEGER DEFAULT 0,
        posts_count INTEGER DEFAULT 0,
        last_post_id INTEGER DEFAULT NULL,
        last_post_time DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (category_id) REFERENCES forum_categories(id) ON DELETE CASCADE
    )");
    ddl_exec("CREATE TABLE IF NOT EXISTS posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        forum_id INTEGER DEFAULT NULL,
        user_id INTEGER NOT NULL,
        title VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        views INTEGER DEFAULT 0,
        replies_count INTEGER DEFAULT 0,
        is_pinned INTEGER DEFAULT 0,
        is_essence INTEGER DEFAULT 0,
        is_locked INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (forum_id) REFERENCES forums(id) ON DELETE SET NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    ddl_exec("CREATE TABLE IF NOT EXISTS replies (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        content TEXT NOT NULL,
        floor INTEGER DEFAULT 0,
        reply_to INTEGER DEFAULT NULL,
        quote_content TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (reply_to) REFERENCES replies(id) ON DELETE SET NULL
    )");
}

/**
 * 迁移版本锁文件路径
 */
function migration_lock_file(): string {
    return DATA_PATH . 'db_version.lock';
}

/**
 * 判断迁移版本是否已是最新（已安装 + 版本一致 → 跳过全量 DDL，大幅提升每请求性能）
 */
function is_migration_up_to_date(): bool {
    // 未完成安装时始终执行迁移（保证安装/重装流程正确）
    if (!file_exists(INSTALLED_FILE)) {
        return false;
    }
    $file = migration_lock_file();
    if (!file_exists($file)) {
        return false;
    }
    $data = @json_decode(@file_get_contents($file), true);
    return is_array($data) && ($data['version'] ?? '') === APP_VERSION;
}

/**
 * 记录迁移完成状态
 */
function mark_migration_done(): void {
    @file_put_contents(migration_lock_file(), json_encode([
        'version' => APP_VERSION,
        'time'    => time(),
    ]), LOCK_EX);
}

/**
 * 结构补丁：补齐历史版本升级或部分安装时可能缺失的关键表与列（幂等）。
 * 使用独立补丁锁文件，保证只执行一次，避免版本锁命中后每个请求重复执行 DDL。
 */
function ensure_schema_patch(PDO $db): void {
    $patchFile = DATA_PATH . 'db_patch_version.lock';
    if ((int)@file_get_contents($patchFile) >= 8) {
        return;
    }
    ensure_mail_logs_table($db);
    migrate_column($db, 'traffic_visitors', 'referrer', "VARCHAR(500) DEFAULT ''");
    migrate_column($db, 'traffic_visitors', 'device_type', "VARCHAR(20) DEFAULT 'unknown'");
    // v2：申诉类型列（封禁申诉 ban / 禁言申诉 mute，旧数据默认 ban）
    migrate_column($db, 'ban_appeals', 'appeal_type', "VARCHAR(20) DEFAULT 'ban'");
    // v3：站点配置表（key-value），并将文件中的 SITE_LANG 同步进库
    ensure_remaining_tables($db);
    if (defined('SITE_LANG') && SITE_LANG !== '') {
        $stmt = $db->prepare("SELECT setting_key FROM site_settings WHERE setting_key = 'site_lang'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $ins = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('site_lang', :v)");
            $ins->execute([':v' => SITE_LANG]);
        }
    }
    // v4：账号级登录锁定列（失败计数 + 锁定到期时间，存量库补齐）
    migrate_column($db, 'users', 'login_fails', 'INTEGER DEFAULT 0');
    migrate_column($db, 'users', 'login_locked_until', 'DATETIME DEFAULT NULL');
    // v5：邮箱披露申请表（社区管理员查看用户邮箱需申请并经超管审核）
    ensure_email_disclosure_table($db);
    // v6：邮箱披露一次性查看列（批准后仅可查看一次）+ 工单系统表
    migrate_column($db, 'email_disclosure_requests', 'viewed_at', 'DATETIME DEFAULT NULL');
    ensure_tickets_table($db);
// v7：工单来源列（user=前台用户反馈 / admin=后台管理员工单）
    migrate_column($db, 'tickets', 'source', "VARCHAR(10) NOT NULL DEFAULT 'admin'");
    // v8：IP 归属地列（后台「IP 库」离线查询结果，国家|区域|省|市|ISP 或 LAN）
    migrate_column($db, 'traffic_visitors', 'region', "VARCHAR(128) DEFAULT ''");
    @file_put_contents($patchFile, '8', LOCK_EX);
}

/**
 * 自动迁移：每个请求只执行一次
 */
function auto_migrate(): void {
    static $initialized = false;
    if ($initialized) return;
    $initialized = true;

    // 未安装时跳过所有迁移/检查（安装流程由 init_db 统一管理，
    // 避免建表完成前触发 auto_expire / ensure_db_indexes 产生错误日志）
    if (!file_exists(INSTALLED_FILE)) {
        return;
    }

    // 每请求检查：到期的封禁/禁言自动解除
    // 必须放在版本锁判断之前，否则版本锁命中后 auto_expire_user_status 被跳过，
    // 导致时间到了的用户无法自动解封/解除禁言
    try {
        $db = get_db();
        auto_expire_user_status($db);
    } catch (\Throwable $e) {
        // 数据库未就绪时静默跳过，不影响安装流程
    }

    // 索引迁移（独立文件版本锁，一次性执行；版本一致时仅读一次小文件，零开销）
    try {
        ensure_db_indexes();
    } catch (\Throwable $e) {
        // 数据库未就绪时静默跳过，下次请求重试
    }

    // 版本锁命中：已安装且迁移版本一致，跳过全量 DDL（本文件最大的每请求开销）
    if (is_migration_up_to_date()) {
        // 结构补丁：历史版本升级/部分安装残留的旧库可能缺失关键表与列，
        // 版本锁命中后无法走全量 DDL，这里用独立补丁锁一次性补齐
        try {
            ensure_schema_patch($db);
        } catch (\Throwable $e) {
            error_log('ensure_schema_patch 失败: ' . $e->getMessage());
        }
        return;
    }

    $db = get_db();
            // 核心父表优先创建：auto_migrate 先于 init_db 执行，
            // reports/notifications 等表带有指向 users/posts/replies 的外键，
            // 全新安装时父表不存在会报 1215 Cannot add foreign key constraint
            ensure_core_tables($db);
            migrate_column($db, 'replies', 'quote_content', 'TEXT DEFAULT NULL');
            migrate_column($db, 'users', 'status', 'VARCHAR(20) DEFAULT \'active\'');
            migrate_column($db, 'users', 'banned_until', 'DATETIME DEFAULT NULL');
            migrate_column($db, 'users', 'muted_until', 'DATETIME DEFAULT NULL');
            migrate_column($db, 'users', 'status_reason', 'TEXT DEFAULT \'\'');

    // 补充 role 列（兼容从旧版本升级的安装）
    migrate_column($db, 'users', 'role', "VARCHAR(20) DEFAULT 'user'");
    $db->exec("UPDATE users SET role = 'user' WHERE role IS NULL OR role = ''");
            migrate_column($db, 'users', 'security_question', 'TEXT DEFAULT NULL');
            migrate_column($db, 'users', 'security_answer_hash', 'TEXT DEFAULT NULL');
            migrate_column($db, 'users', 'force_password_change', 'INTEGER DEFAULT 0');
            // 账号级登录锁定列（存量库补齐，全新安装由 CREATE TABLE 直接包含）
            migrate_column($db, 'users', 'login_fails', 'INTEGER DEFAULT 0');
            migrate_column($db, 'users', 'login_locked_until', 'DATETIME DEFAULT NULL');
            ensure_reports_table($db);
            ensure_notifications_table($db);
            ensure_mail_logs_table($db);
            ensure_password_reset_requests_table($db);
            migrate_column($db, 'password_reset_requests', 'has_security_question', 'INTEGER DEFAULT 0');
            migrate_column($db, 'password_reset_requests', 'security_verified', 'INTEGER DEFAULT 0');
            ensure_sensitive_words_tables($db);
            init_default_sensitive_words($db);
            ensure_ban_appeals_table($db);
            ensure_email_disclosure_table($db);
            ensure_tickets_table($db);
            ensure_site_pages_table($db);
            init_default_site_pages($db);
            ensure_announcements_table($db);
            init_default_announcements($db);
            // 核心表自动迁移（旧版数据库/部分安装修复）
            ensure_forum_categories_table($db);
            init_default_forum_categories($db);
            ensure_forums_table($db);
            migrate_column($db, 'forums', 'last_post_time', 'DATETIME DEFAULT NULL');
            init_default_forums($db);
            migrate_column($db, 'traffic_visitors', 'referrer', 'VARCHAR(500) DEFAULT \'\'');
            migrate_column($db, 'traffic_visitors', 'device_type', 'VARCHAR(20) DEFAULT \'unknown\'');
            // 角色/勋章相关表（安装中断或旧版升级时可能缺失）
            ensure_user_groups();
            ensure_roles_table();
            ensure_user_roles_table();
            ensure_default_medals();
            ensure_user_medals_table();
            ensure_pm_tables();
            // 兜底：创建安装流程中可能遗漏的其他表（favorites / checkins / traffic 等）
            ensure_remaining_tables($db);
            // 迁移完成，记录版本锁（避免后续请求重复执行全量 DDL）
            mark_migration_done();
}

/**
 * 检查论坛是否完整安装（锁文件 + 数据库完整性双重验证）
 * 防止部分安装残留导致页面白屏/500错误
 */
function is_forum_installed(): bool {
    if (!file_exists(INSTALLED_FILE)) {
        return false;
    }
    // 30 秒文件缓存：安装后无需每个请求执行 getTables()（SHOW TABLES / sqlite_master 查询）
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cacheFile = APP_ROOT . 'data/installed_check.cache';
    if (is_file($cacheFile)) {
        // JSON 格式缓存；旧 serialize 格式文件 json_decode 失败返回 null，视为 miss 自然重建
        $data = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($data) && isset($data['time']) && (time() - (int)$data['time']) < 30) {
            $cache = (bool)$data['ok'];
            return $cache;
        }
    }
    try {
        $driver = get_db_driver();
        $tables = $driver->getTables();
        $requiredTables = ['users', 'posts', 'forums', 'replies', 'forum_categories'];
        $missing = array_diff($requiredTables, $tables);
        $ok = empty($missing);
        @file_put_contents($cacheFile, json_encode(['time' => time(), 'ok' => $ok]), LOCK_EX);
        $cache = $ok;
        return $ok;
    } catch (\Throwable $e) {
        $cache = false;
        return false;
    }
}

/**
 * 自动解封/解除禁言
 *
 * 封禁或禁言到期后，users 表中的 status 字段不会自动变为 active。
 * 此函数在每个请求初始化数据库时执行一次，将已到期的封禁/禁言记录统一更新为 active。
 * 这样所有页面（前台、后台、AJAX）拿到的用户状态都是最新的，无需各自处理。
 *
 * 注意：SQLite 的 CURRENT_TIMESTAMP 存储的是 UTC 时间，因此这里使用 gmdate('Y-m-d H:i:s') 比较。
 */
function auto_expire_user_status(PDO $db): void {
    try {
        // 节流：每 60 秒最多执行一次到期检查（文件时间戳），避免每个请求执行 2 条全表 UPDATE。
        // 解封/解除禁言的实时性由 is_user_banned/is_user_muted 的 until 字段判断 +
        // 前端轮询（check_ban_status 等）保证；此处仅兜底清理 status 字段，延迟执行无感知。
        $lockFile = APP_ROOT . 'data/auto_expire.lock';
        if (is_file($lockFile) && (time() - (int)@file_get_contents($lockFile)) < 60) {
            return;
        }
        @file_put_contents($lockFile, time(), LOCK_EX);

        $now = gmdate('Y-m-d H:i:s');
        // 解封：status='banned' 且有到期时间且已到期
        $db->prepare("UPDATE users SET status = 'active', banned_until = NULL, status_reason = '' WHERE status = 'banned' AND banned_until IS NOT NULL AND banned_until < :now")
            ->execute([':now' => $now]);
        // 解除禁言：status='muted' 且有到期时间且已到期
        $db->prepare("UPDATE users SET status = 'active', muted_until = NULL, status_reason = '' WHERE status = 'muted' AND muted_until IS NOT NULL AND muted_until < :now")
            ->execute([':now' => $now]);
    } catch (Exception $e) {
        error_log('auto_expire_user_status failed: ' . $e->getMessage());
    }
}

/**
 * 索引迁移（独立文件版本锁，一次性执行）
 *
 * 修复 MySQL 部署下核心查询的全表扫描 + filesort 问题：
 * - 首页"最新帖子"、版块列表排序缺少 created_at/组合索引
 * - 回复列表、用户回复列表缺少索引
 * - 后台待办计数（reports/ban_appeals/password_reset_requests 的 status 列）缺少索引
 *
 * 使用独立版本锁（data/db_index_version.lock），避免与主迁移版本锁冲突；
 * 每请求仅读一次小文件（版本一致即返回），索引建立后零开销。
 * 失败时不写锁，下次请求自动重试（ddl_exec 对重复索引错误幂等跳过）。
 */
function ensure_db_indexes(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $idxFile = APP_ROOT . 'data/db_index_version.lock';
    $currentIdxVersion = 4; // 新增索引时递增此版本号
    if ((int)@file_get_contents($idxFile) >= $currentIdxVersion) {
        return;
    }

    try {
        // 按表存在性建索引：安装早期某些表（ban_appeals 等）由 auto_migrate 兜底创建，
        // 表未就绪时跳过该表索引，避免每请求全量重试 DDL（MySQL 下 CREATE INDEX 会隐式提交事务）
        $tables = array_flip(get_db_driver()->getTables());
        $indexes = [
            // 帖子：首页"最新帖子"排序 / 版块列表（置顶优先 + 更新时间）
            'posts' => [
                'idx_posts_created_at'     => 'posts(created_at)',
                'idx_posts_forum_updated'  => 'posts(forum_id, is_pinned, updated_at)',
                'idx_posts_user'           => 'posts(user_id)',
            ],
            // 回复：帖子详情回复列表 / 用户回复列表
            'replies' => [
                'idx_replies_post_created' => 'replies(post_id, created_at)',
                'idx_replies_user'         => 'replies(user_id)',
            ],
            // 用户：最新注册用户
            'users' => [
                'idx_users_created'        => 'users(created_at)',
            ],
            // 后台待办计数（pending 统计）
            'reports' => [
                'idx_reports_status'       => 'reports(status)',
            ],
            'ban_appeals' => [
                'idx_ban_appeals_status'   => 'ban_appeals(status)',
            ],
            'password_reset_requests' => [
                'idx_prr_status'           => 'password_reset_requests(status)',
            ],
            // 站内信：会话/未读查询（pm_messages 表无 recipient_id 列，收件箱按 sender_id/conversation_id 查询）
            'pm_messages' => [
                'idx_pm_messages_conv'     => 'pm_messages(conversation_id, created_at)',
            ],
            // v4：头部/私信/公告热路径索引
            // 通知：头部未读通知查询（idx_notifications_user 三列索引已可覆盖，此处按规格补双列索引）
            'notifications' => [
                'idx_notifications_user_read' => 'notifications(user_id, is_read)',
            ],
            // 收藏：头部下拉统计与收藏列表（UNIQUE(user_id, post_id) 前缀亦可服务，按规格显式补建）
            'favorites' => [
                'idx_favorites_user'       => 'favorites(user_id)',
            ],
            // 私信会话：未读子查询按 user1_id / user2_id 两侧检索
            'pm_conversations' => [
                'idx_pm_conv_user1'        => 'pm_conversations(user1_id)',
                'idx_pm_conv_user2'        => 'pm_conversations(user2_id)',
            ],
            // 公告：每页头部 is_active 公告查询
            'announcements' => [
                'idx_announcements_active' => 'announcements(is_active)',
            ],
            // 流量统计：traffic_visitors 表实际列为 visit_date / last_visit，
            // 已在 ensure_remaining_tables 中建 idx_visitors_date / idx_visitors_last_visit，
            // 此处不再重复建索引（曾误用不存在的 visit_time 列导致每请求 DDL 重试）
        ];
        foreach ($indexes as $table => $list) {
            if (!isset($tables[$table])) {
                continue; // 表尚未创建（由 auto_migrate 的 ensure_* 兜底建表并带索引）
            }
            foreach ($list as $name => $def) {
                ddl_exec("CREATE INDEX IF NOT EXISTS {$name} ON {$def}");
            }
        }

        @file_put_contents($idxFile, (string)$currentIdxVersion, LOCK_EX);
    } catch (\Throwable $e) {
        error_log('ensure_db_indexes failed: ' . $e->getMessage());
    }
}

/**
 * 确保举报表存在（用于自动迁移）
 */
function ensure_reports_table(PDO $db): void {
    ddl_exec("CREATE TABLE IF NOT EXISTS reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER DEFAULT NULL,
        reply_id INTEGER DEFAULT NULL,
        reporter_id INTEGER NOT NULL,
        reason_type VARCHAR(50) NOT NULL DEFAULT 'other',
        reason TEXT DEFAULT '',
        status VARCHAR(20) DEFAULT 'pending',
        admin_note TEXT DEFAULT '',
        handled_by INTEGER DEFAULT NULL,
        handled_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
        FOREIGN KEY (reply_id) REFERENCES replies(id) ON DELETE CASCADE,
        FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_reports_status ON reports(status, created_at DESC)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_reports_reporter ON reports(reporter_id, created_at DESC)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_reports_target ON reports(reply_id, status)");
}

/**
 * 确保消息通知表存在（兼容已安装站点）
 */
function ensure_notifications_table(PDO $db): void {
    ddl_exec("CREATE TABLE IF NOT EXISTS notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        type VARCHAR(50) NOT NULL DEFAULT 'reply',
        title VARCHAR(255) NOT NULL,
        content TEXT DEFAULT '',
        link VARCHAR(500) DEFAULT NULL,
        is_read INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id, is_read, created_at DESC)");
}

/**
 * 确保邮件日志表存在（兼容已安装站点，每次请求初始化时自动检查）
 */
function ensure_mail_logs_table(PDO $db): void {
    ddl_exec("CREATE TABLE IF NOT EXISTS mail_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        recipient VARCHAR(255) NOT NULL,
        recipient_name VARCHAR(100) DEFAULT '',
        subject VARCHAR(255) NOT NULL DEFAULT '',
        type VARCHAR(50) NOT NULL DEFAULT 'other',
        status VARCHAR(20) NOT NULL DEFAULT 'success',
        error_message TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_mail_logs_created ON mail_logs(created_at DESC)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_mail_logs_status ON mail_logs(status, created_at DESC)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_mail_logs_type ON mail_logs(type, created_at DESC)");

    // 退信处理相关字段（兼容已安装站点，自动迁移）
    migrate_column($db, 'mail_logs', 'message_id', 'VARCHAR(255) DEFAULT NULL');
    migrate_column($db, 'mail_logs', 'bounce_status', "VARCHAR(20) DEFAULT 'pending'"); // pending/bounced/not_bounced
    migrate_column($db, 'mail_logs', 'bounce_type', 'VARCHAR(20) DEFAULT NULL');     // hard/soft
    migrate_column($db, 'mail_logs', 'bounce_reason', 'TEXT DEFAULT NULL');
    migrate_column($db, 'mail_logs', 'bounce_time', 'DATETIME DEFAULT NULL');

    ddl_exec("CREATE INDEX IF NOT EXISTS idx_mail_logs_message_id ON mail_logs(message_id)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_mail_logs_bounce ON mail_logs(bounce_status, created_at DESC)");

    // 退信处理配置表
    ddl_exec("CREATE TABLE IF NOT EXISTS mail_bounce_config (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        enabled INTEGER DEFAULT 0,
        protocol VARCHAR(10) DEFAULT 'imap',
        host VARCHAR(255) DEFAULT '',
        port INTEGER DEFAULT 993,
        encryption VARCHAR(10) DEFAULT 'ssl',
        username VARCHAR(255) DEFAULT '',
        password VARCHAR(255) DEFAULT '',
        mailbox VARCHAR(100) DEFAULT 'INBOX',
        last_check DATETIME DEFAULT NULL,
        last_check_count INTEGER DEFAULT 0,
        auto_check INTEGER DEFAULT 1,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    ddl_exec("INSERT OR IGNORE INTO mail_bounce_config (id) VALUES (1)");

    // 退信处理日志表（记录每次检查的处理情况）
    ddl_exec("CREATE TABLE IF NOT EXISTS mail_bounce_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        check_time DATETIME DEFAULT CURRENT_TIMESTAMP,
        found_count INTEGER DEFAULT 0,
        processed_count INTEGER DEFAULT 0,
        error_message TEXT DEFAULT '',
        details TEXT DEFAULT ''
    )");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_bounce_logs_time ON mail_bounce_logs(check_time DESC)");
}

/**
 * 确保密码重置申请表存在（兼容已安装站点）
 */
function ensure_password_reset_requests_table(PDO $db): void {
    ddl_exec("CREATE TABLE IF NOT EXISTS password_reset_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        email VARCHAR(128) NOT NULL,
        status VARCHAR(20) DEFAULT 'pending',
        has_security_question INTEGER DEFAULT 0,
        security_verified INTEGER DEFAULT 0,
        admin_note TEXT DEFAULT '',
        handled_by INTEGER DEFAULT NULL,
        handled_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_prr_status ON password_reset_requests(status, created_at DESC)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_prr_user ON password_reset_requests(user_id, status)");
}

/**
 * 确保封禁申诉表存在
 */
function ensure_ban_appeals_table(PDO $db): void {
    ddl_exec("CREATE TABLE IF NOT EXISTS ban_appeals (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        username VARCHAR(64) DEFAULT '',
        email VARCHAR(128) DEFAULT '',
        ban_reason TEXT DEFAULT '',
        ban_until DATETIME DEFAULT NULL,
        appeal_reason TEXT NOT NULL,
        status VARCHAR(20) DEFAULT 'pending',
        admin_note TEXT DEFAULT '',
        handled_by INTEGER DEFAULT NULL,
        handled_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_ba_status ON ban_appeals(status, created_at DESC)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_ba_user ON ban_appeals(user_id, created_at DESC)");
    // 申诉类型：'ban' 封禁申诉 / 'mute' 禁言申诉（旧数据默认 ban）
    migrate_column($db, 'ban_appeals', 'appeal_type', "VARCHAR(20) DEFAULT 'ban'");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_ba_type ON ban_appeals(appeal_type, status)");
}

/**
 * 确保邮箱披露申请表存在
 *
 * 社区管理员默认隐藏用户邮箱（隐私保护），可对目标用户发起披露申请并说明原因，
 * 由超级管理员审核（pending -> approved / rejected）；审核通过后申请人可见该用户邮箱。
 */
function ensure_email_disclosure_table(PDO $db): void {
    ddl_exec("CREATE TABLE IF NOT EXISTS email_disclosure_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        applicant_id INTEGER NOT NULL,
        target_user_id INTEGER NOT NULL,
        reason TEXT NOT NULL,
        status VARCHAR(20) DEFAULT 'pending',
        admin_note TEXT DEFAULT '',
        handled_by INTEGER DEFAULT NULL,
        handled_at DATETIME DEFAULT NULL,
        viewed_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (applicant_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_edr_status ON email_disclosure_requests(status, created_at DESC)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_edr_applicant ON email_disclosure_requests(applicant_id, created_at DESC)");
}

/**
 * 确保工单表存在（工单系统：社区管理员与超级管理员协作反馈/处理问题）
 */
function ensure_tickets_table(PDO $db): void {
    ddl_exec("CREATE TABLE IF NOT EXISTS tickets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title VARCHAR(200) NOT NULL,
        content TEXT NOT NULL,
        reporter_id INTEGER NOT NULL,
        source VARCHAR(10) NOT NULL DEFAULT 'admin',
        status VARCHAR(20) DEFAULT 'open',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    ddl_exec("CREATE TABLE IF NOT EXISTS ticket_replies (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        content TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_tickets_status ON tickets(status, created_at DESC)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_ticket_replies_ticket ON ticket_replies(ticket_id, created_at)");
}

/**
 * 确保站点页面表存在（用户协议、隐私政策等可编辑页面）
 */
function ensure_site_pages_table(PDO $db): void {
    ddl_exec("CREATE TABLE IF NOT EXISTS site_pages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug VARCHAR(50) UNIQUE NOT NULL,
        title VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
}

/**
 * 确保公告表存在（旧版数据库迁移）
 */
function ensure_announcements_table(PDO $db): void {
    ddl_exec("CREATE TABLE IF NOT EXISTS announcements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        is_active INTEGER DEFAULT 1,
        display_order INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
}

/**
 * 初始化默认公告
 */
function init_default_announcements(PDO $db): void {
    $annCount = (int)$db->query("SELECT COUNT(*) FROM announcements")->fetchColumn();
    if ($annCount === 0) {
        $db->exec("INSERT INTO announcements (title, content, is_active, display_order) VALUES ('欢迎来到云界论坛', '云界论坛正式开站！欢迎大家注册交流。', 1, 0)");
    }
}

/**
 * 确保版块分类表存在（旧版数据库迁移）
 */
function ensure_forum_categories_table(PDO $db): void {
    ddl_exec("CREATE TABLE IF NOT EXISTS forum_categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(100) NOT NULL,
        display_order INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
}

/**
 * 初始化默认版块分类
 */
function init_default_forum_categories(PDO $db): void {
    $count = (int)$db->query("SELECT COUNT(*) FROM forum_categories")->fetchColumn();
    if ($count === 0) {
        $db->exec("INSERT INTO forum_categories (name, display_order) VALUES ('技术交流', 0)");
        $db->exec("INSERT INTO forum_categories (name, display_order) VALUES ('生活娱乐', 1)");
        $db->exec("INSERT INTO forum_categories (name, display_order) VALUES ('站务管理', 2)");
    }
}

/**
 * 确保版块表存在（旧版数据库迁移）
 */
function ensure_forums_table(PDO $db): void {
    ddl_exec("CREATE TABLE IF NOT EXISTS forums (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER NOT NULL,
        name VARCHAR(100) NOT NULL,
        description TEXT DEFAULT '',
        icon VARCHAR(50) DEFAULT 'folder',
        display_order INTEGER DEFAULT 0,
        threads_count INTEGER DEFAULT 0,
        posts_count INTEGER DEFAULT 0,
        last_post_id INTEGER DEFAULT NULL,
        last_post_time DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (category_id) REFERENCES forum_categories(id) ON DELETE CASCADE
    )");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_forums_category ON forums(category_id, display_order)");
}

/**
 * 初始化默认版块
 */
function init_default_forums(PDO $db): void {
    $count = (int)$db->query("SELECT COUNT(*) FROM forums")->fetchColumn();
    if ($count > 0) {
        return;
    }
    $stmt = $db->query("SELECT id FROM forum_categories ORDER BY id ASC");
    $catIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($catIds) < 3) {
        return; // 分类数据不足
    }
    $defaultForums = [
        [$catIds[0], '后端开发', 'PHP、Python、Java、Go 等后端技术讨论', 'code', 0],
        [$catIds[0], '前端开发', 'HTML/CSS/JS、Vue、React 等前端技术讨论', 'design', 1],
        [$catIds[0], '数据库', 'MySQL、SQLite、Redis 等数据库讨论', 'database', 2],
        [$catIds[1], '闲聊灌水', '日常闲聊、灌水交友', 'chat', 0],
        [$catIds[1], '资源分享', '好文好书好工具分享', 'gift', 1],
        [$catIds[2], '站点公告', '论坛官方公告与动态', 'announcement', 0],
        [$catIds[2], '意见反馈', '论坛建议与问题反馈', 'help', 1],
    ];
    $stmt = $db->prepare("INSERT INTO forums (category_id, name, description, icon, display_order) VALUES (:cat, :name, :desc, :icon, :order)");
    foreach ($defaultForums as $f) {
        $stmt->execute([':cat' => $f[0], ':name' => $f[1], ':desc' => $f[2], ':icon' => $f[3], ':order' => $f[4]]);
    }
}

/**
 * 确保敏感词表存在
 */
function ensure_sensitive_words_tables(PDO $db): void {
    ddl_exec("CREATE TABLE IF NOT EXISTS sensitive_words (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        word VARCHAR(255) NOT NULL,
        category VARCHAR(50) DEFAULT '其他',
        level INTEGER DEFAULT 1,
        match_mode VARCHAR(20) DEFAULT 'exact',
        replacement VARCHAR(255) DEFAULT '***',
        enabled INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_sw_enabled ON sensitive_words(enabled, level)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_sw_word ON sensitive_words(word)");

    ddl_exec("CREATE TABLE IF NOT EXISTS sensitive_word_whitelist (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        word VARCHAR(255) NOT NULL,
        enabled INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_sww_enabled ON sensitive_word_whitelist(enabled)");

    ddl_exec("CREATE TABLE IF NOT EXISTS sensitive_word_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        content_type VARCHAR(50) NOT NULL,
        content_id INTEGER DEFAULT NULL,
        matched_word VARCHAR(255) NOT NULL,
        original_snippet TEXT DEFAULT '',
        action VARCHAR(50) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_swl_user ON sensitive_word_logs(user_id, created_at DESC)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_swl_time ON sensitive_word_logs(created_at DESC)");

    // 敏感词启用/禁用操作审计日志表（记录每次状态变更的执行者、时间、来源）
    ddl_exec("CREATE TABLE IF NOT EXISTS sensitive_word_status_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        word_id INTEGER NOT NULL,
        word VARCHAR(255) NOT NULL DEFAULT '',
        action VARCHAR(20) NOT NULL,
        operator_id INTEGER NOT NULL DEFAULT 0,
        operator_name VARCHAR(64) DEFAULT '',
        source VARCHAR(30) DEFAULT 'manual',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_swsl_word ON sensitive_word_status_logs(word_id, created_at DESC)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_swsl_operator ON sensitive_word_status_logs(operator_id, created_at DESC)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_swsl_time ON sensitive_word_status_logs(created_at DESC)");

    // 访问流量统计表（按小时聚合，减少行数）
    ddl_exec("CREATE TABLE IF NOT EXISTS traffic_stats (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        stat_date DATE NOT NULL,
        stat_hour INTEGER NOT NULL,
        page_views INTEGER DEFAULT 0,
        unique_visitors INTEGER DEFAULT 0,
        UNIQUE(stat_date, stat_hour)
    )");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_traffic_date ON traffic_stats(stat_date, stat_hour)");

    // 访客记录表（用于去重统计 UV，按 IP+日期去重）
    ddl_exec("CREATE TABLE IF NOT EXISTS traffic_visitors (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        visit_date DATE NOT NULL,
        ip_hash VARCHAR(64) NOT NULL,
        user_agent VARCHAR(500) DEFAULT '',
        page VARCHAR(255) DEFAULT '',
        region VARCHAR(128) DEFAULT '',
        first_visit DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_visit DATETIME DEFAULT CURRENT_TIMESTAMP,
        views INTEGER DEFAULT 1,
        UNIQUE(visit_date, ip_hash)
    )");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_visitors_date ON traffic_visitors(visit_date)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_visitors_last_visit ON traffic_visitors(last_visit)");

    // 站点配置表（key-value）：存储站点级配置（如全站语言 site_lang），
    // 避免依赖 include 的 site_config.php 文件（生产环境 OPcache 下文件变更不生效）
    ddl_exec("CREATE TABLE IF NOT EXISTS site_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT DEFAULT ''
    )");
}

/**
 * 读取站点配置项（key-value），静态缓存：每请求只查询一次全表
 */
function get_site_setting(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $db = get_db();
            $rows = $db->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
            $cache = is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            // 表不存在（未安装/迁移未执行）时静默返回默认值
        }
    }
    return $cache[$key] ?? $default;
}

/**
 * 写入站点配置项（key-value），跨 SQLite / MySQL / PostgreSQL 通用（先查后写，避免 UPSERT 语法差异）
 */
function set_site_setting(string $key, string $value): bool {
    try {
        $db = get_db();
        $stmt = $db->prepare("SELECT setting_key FROM site_settings WHERE setting_key = :k");
        $stmt->execute([':k' => $key]);
        if ($stmt->fetch()) {
            $stmt = $db->prepare("UPDATE site_settings SET setting_value = :v WHERE setting_key = :k");
        } else {
            $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (:k, :v)");
        }
        $stmt->execute([':k' => $key, ':v' => $value]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * 兜底迁移：创建安装流程中可能遗漏的所有剩余表
 *
 * 与 init_db() 中的 DDL 保持同步，使用 CREATE TABLE IF NOT EXISTS 幂等创建，
 * 不影响已安装站点的存量数据。
 */
function ensure_remaining_tables(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;

    // 签到记录表
    ddl_exec("CREATE TABLE IF NOT EXISTS checkins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        checkin_date DATE NOT NULL,
        points INTEGER DEFAULT 0,
        streak INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE(user_id, checkin_date)
    )");

    // 积分/金币流水日志
    ddl_exec("CREATE TABLE IF NOT EXISTS user_points_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        points INTEGER NOT NULL DEFAULT 0,
        coins INTEGER NOT NULL DEFAULT 0,
        type VARCHAR(50) NOT NULL,
        source_type VARCHAR(50) DEFAULT NULL,
        source_id INTEGER DEFAULT NULL,
        description TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_points_log_user ON user_points_log(user_id, created_at DESC)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_points_log_type ON user_points_log(user_id, type, created_at DESC)");

    // 收藏表
    ddl_exec("CREATE TABLE IF NOT EXISTS favorites (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        post_id INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
        UNIQUE(user_id, post_id)
    )");

    // 退信处理配置表
    ddl_exec("CREATE TABLE IF NOT EXISTS mail_bounce_config (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        enabled INTEGER DEFAULT 0,
        protocol VARCHAR(10) DEFAULT 'imap',
        host VARCHAR(255) DEFAULT '',
        port INTEGER DEFAULT 993,
        encryption VARCHAR(10) DEFAULT 'ssl',
        username VARCHAR(255) DEFAULT '',
        password VARCHAR(255) DEFAULT '',
        mailbox VARCHAR(100) DEFAULT 'INBOX',
        last_check DATETIME DEFAULT NULL,
        last_check_count INTEGER DEFAULT 0,
        auto_check INTEGER DEFAULT 1,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    ddl_exec("INSERT OR IGNORE INTO mail_bounce_config (id) VALUES (1)");

    // 退信处理日志表
    ddl_exec("CREATE TABLE IF NOT EXISTS mail_bounce_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        check_time DATETIME DEFAULT CURRENT_TIMESTAMP,
        found_count INTEGER DEFAULT 0,
        processed_count INTEGER DEFAULT 0,
        error_message TEXT DEFAULT '',
        details TEXT DEFAULT ''
    )");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_bounce_logs_time ON mail_bounce_logs(check_time DESC)");

    // 访问流量统计表（按小时聚合）
    ddl_exec("CREATE TABLE IF NOT EXISTS traffic_stats (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        stat_date DATE NOT NULL,
        stat_hour INTEGER NOT NULL,
        page_views INTEGER DEFAULT 0,
        unique_visitors INTEGER DEFAULT 0,
        UNIQUE(stat_date, stat_hour)
    )");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_traffic_date ON traffic_stats(stat_date, stat_hour)");

    // 访客记录表（用于去重统计 UV）
    ddl_exec("CREATE TABLE IF NOT EXISTS traffic_visitors (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        visit_date DATE NOT NULL,
        ip_hash VARCHAR(64) NOT NULL,
        user_agent VARCHAR(500) DEFAULT '',
        page VARCHAR(255) DEFAULT '',
        region VARCHAR(128) DEFAULT '',
        first_visit DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_visit DATETIME DEFAULT CURRENT_TIMESTAMP,
        views INTEGER DEFAULT 1,
        UNIQUE(visit_date, ip_hash)
    )");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_visitors_date ON traffic_visitors(visit_date)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_visitors_last_visit ON traffic_visitors(last_visit)");
}

/**
 * 初始化默认敏感词（自动补充缺失词条，可重复执行）
 */
function init_default_sensitive_words(PDO $db): void {
    // 快速路径：如果敏感词表已有数据，跳过耗时的比对初始化
    // 仅在表为空时才执行完整初始化，减少每请求开销
    $hasData = (bool)$db->query("SELECT 1 FROM sensitive_words LIMIT 1")->fetchColumn();
    if ($hasData) {
        return;
    }

    $defaults = [
        /* ============================================================
         * 一、政治敏感（拦截级 level=2）
         * 涉及分裂国家、颠覆政权、政治运动、社会事件等
         * ============================================================ */
        // 分裂势力
        ['word' => '法轮功', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '台独', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '疆独', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '藏独', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '港独', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '反华', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '反党', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '反动', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        // 政权相关
        ['word' => '颠覆国家政权', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '煽动颠覆', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '政权更迭', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '颜色革命', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '政治迫害', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        // 社会事件
        ['word' => '游行示威', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '集会抗议', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '罢课', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '罢工', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '静坐', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '上访', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '维稳', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '签名运动', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '敏感事件', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        // 审查相关（替换级）
        ['word' => '言论审查', 'category' => '政治敏感', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '网络审查', 'category' => '政治敏感', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '翻墙', 'category' => '政治敏感', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '科学上网', 'category' => '政治敏感', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],

        /* ============================================================
         * 二、违法犯罪（拦截级 level=2）
         * 涉及刑事犯罪、经济犯罪、违禁品等
         * ============================================================ */
        // 经济犯罪
        ['word' => '走私', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '洗钱', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '黑钱', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '行贿', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '受贿', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '贪腐', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '假币', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '偷渡', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        // 违禁品
        ['word' => '违禁品', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '枪支', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '弹药', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '爆炸物', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '雷管', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '管制刀具', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        // 赌博
        ['word' => '赌博', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '博彩', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '彩票预测', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        // 其他违法
        ['word' => '违建', 'category' => '违法犯罪', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],

        /* ============================================================
         * 三、毒品相关（拦截级 level=2）
         * 涉及毒品名称、吸毒贩毒等
         * ============================================================ */
        ['word' => '吸毒', 'category' => '毒品相关', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '贩毒', 'category' => '毒品相关', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '毒品', 'category' => '毒品相关', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '冰毒', 'category' => '毒品相关', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '海洛因', 'category' => '毒品相关', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '大麻', 'category' => '毒品相关', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '可卡因', 'category' => '毒品相关', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '罂粟', 'category' => '毒品相关', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],

        /* ============================================================
         * 四、网络安全（拦截级 level=2）
         * 涉及黑客攻击、木马病毒、钓鱼等
         * ============================================================ */
        ['word' => '黑客', 'category' => '网络安全', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '盗号', 'category' => '网络安全', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '木马', 'category' => '网络安全', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '钓鱼网站', 'category' => '网络安全', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '钓鱼链接', 'category' => '网络安全', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],

        /* ============================================================
         * 五、诈骗（拦截级 level=2 + 替换级 level=1）
         * 涉及网络诈骗、传销、非法集资等
         * ============================================================ */
        ['word' => '网络诈骗', 'category' => '诈骗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '诈骗', 'category' => '诈骗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '骗局', 'category' => '诈骗', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '骗子', 'category' => '诈骗', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '传销', 'category' => '诈骗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '传销组织', 'category' => '诈骗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '非法集资', 'category' => '诈骗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '校园贷', 'category' => '诈骗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '裸贷', 'category' => '诈骗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '杀猪盘', 'category' => '诈骗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '套路', 'category' => '诈骗', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        /* ============================================================
         * 六、色情低俗（替换级 level=1 + 拦截级 level=2）
         * 涉及色情内容、性服务、淫秽描述等
         * ============================================================ */
        // 色情内容
        ['word' => '色情', 'category' => '色情低俗', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '淫秽', 'category' => '色情低俗', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '色情片', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '三级片', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '黄片', 'category' => '色情低俗', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '小电影', 'category' => '色情低俗', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => 'AV', 'category' => '色情低俗', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        // 性服务
        ['word' => '嫖娼', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '卖淫', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '性服务', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '一夜情', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '裸聊', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '约炮', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '援交', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        // 涉性描述
        ['word' => '性爱', 'category' => '色情低俗', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '性交', 'category' => '色情低俗', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '性行为', 'category' => '色情低俗', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '性奴', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '强奸', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '乱伦', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '处女', 'category' => '色情低俗', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        // 裸露相关
        ['word' => '裸照', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '裸体', 'category' => '色情低俗', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '裸睡', 'category' => '色情低俗', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        // 挑逗性描述
        ['word' => '诱惑', 'category' => '色情低俗', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '风骚', 'category' => '色情低俗', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '骚货', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '浪货', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],

        /* ============================================================
         * 七、辱骂人身攻击（替换级 level=1）
         * 涉及脏话、侮辱性词汇、人身攻击等
         * ============================================================ */
        // 直接脏话
        ['word' => '傻逼', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '傻B', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '傻逼玩意', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '****'],
        ['word' => 'SB', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '煞笔', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '傻叉', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '二逼', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '逗比', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '傻冒', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '沙雕', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        // 涉及家人
        ['word' => '他妈的', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '妈的', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '草泥马', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '操你妈', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '尼玛', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '你妈', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '他妈', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '他娘', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '娘的', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '狗日', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '死妈', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '妈逼', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '死全家', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => 'cnm', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '***'],
        // 感叹式脏话
        ['word' => '艹', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '*'],
        ['word' => '我操', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '卧槽', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '我靠', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '牛逼', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        // 智力侮辱
        ['word' => '脑残', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '脑瘫', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '智障', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '弱智', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '白痴', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '神经病', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '***'],
        // 人身攻击
        ['word' => '废物', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '垃圾', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '贱人', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '婊子', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '混蛋', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '王八蛋', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '去死', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '滚蛋', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],

        /* ============================================================
         * 八、广告引流（替换级 level=1 + 拦截级 level=2）
         * 涉及小广告、刷单、引流、联系方式等
         * ============================================================ */
        // 通用广告
        ['word' => '垃圾广告', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '小广告', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '广告群发', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '刷屏', 'category' => '广告引流', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '代购', 'category' => '广告引流', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '低价出售', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '代开发票', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        // 刷单兼职
        ['word' => '刷单', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '兼职刷单', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '网络兼职', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '高薪兼职', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        // 赚钱诱导
        ['word' => '日赚', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '月入过万', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '轻松赚钱', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '稳赚不赔', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '内部消息', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        // 免费领取
        ['word' => '免费领', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '免费送', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '扫码领', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        // 虚假中奖
        ['word' => '虚假中奖', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '中奖诈骗', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        // 联系方式引流
        ['word' => '加微信', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '加QQ', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '加我微信', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '加我QQ', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '微信号', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => 'QQ号', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '咨询QQ', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '私聊我', 'category' => '广告引流', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => 'V信', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '威信', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '扣扣', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],

        /* ============================================================
         * 九、隐私骚扰（替换级 level=1 + 拦截级 level=2）
         * 涉及人肉、跟踪、威胁、恐吓等
         * ============================================================ */
        ['word' => '人肉搜索', 'category' => '隐私骚扰', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '曝光隐私', 'category' => '隐私骚扰', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '开盒', 'category' => '隐私骚扰', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '跟踪狂', 'category' => '隐私骚扰', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '威胁', 'category' => '隐私骚扰', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '恐吓', 'category' => '隐私骚扰', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '勒索', 'category' => '隐私骚扰', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '骚扰', 'category' => '隐私骚扰', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],

        /* ============================================================
         * 十、补充扩展（各分类漏词、变体、网络黑话）
         * ============================================================ */
        // 政治敏感补充
        ['word' => '港灿', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '黄丝', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '废青', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '港英', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '光复', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '时代革命', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '五大诉求', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],

        // 违法犯罪补充
        ['word' => '私服', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '外挂', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '作弊器', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '黑客工具', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '走私车', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '水车', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '套牌', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '伪基站', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '黑产', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '灰产', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '黑卡', 'category' => '违法犯罪', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],

        // 网络安全补充
        ['word' => '后门', 'category' => '网络安全', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '勒索病毒', 'category' => '网络安全', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '挖矿程序', 'category' => '网络安全', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => 'DDoS', 'category' => '网络安全', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => 'CC攻击', 'category' => '网络安全', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],

        // 诈骗补充
        ['word' => '空包网', 'category' => '诈骗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '刷单平台', 'category' => '诈骗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '资金盘', 'category' => '诈骗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '庞氏', 'category' => '诈骗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '跑路', 'category' => '诈骗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '崩盘跑路', 'category' => '诈骗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '兼职陷阱', 'category' => '诈骗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],

        // 色情低俗补充
        ['word' => '妓女', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '男宠', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '包夜', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '上门服务', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '特殊服务', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '推油', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '丝袜', 'category' => '色情低俗', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '打炮', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],

        // 辱骂攻击补充
        ['word' => 'NMSL', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '****'],
        ['word' => 'NM$L', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '****'],
        ['word' => '孝子', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '急了', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '破防', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '小丑', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '跳梁', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '奇葩', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '下头', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '绿茶婊', 'category' => '辱骂攻击', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '渣男', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '渣女', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '渣男渣女', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '缺德', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],

        // 广告引流补充
        ['word' => '私我', 'category' => '广告引流', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '推广链接', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '淘宝客', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '互点', 'category' => '广告引流', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '互赞', 'category' => '广告引流', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '涨粉', 'category' => '广告引流', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '上热门', 'category' => '广告引流', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],

        // 隐私骚扰补充
        ['word' => '查水表', 'category' => '隐私骚扰', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '查开房', 'category' => '隐私骚扰', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '查轨迹', 'category' => '隐私骚扰', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '社工', 'category' => '隐私骚扰', 'level' => 2, 'match_mode' => 'word', 'replacement' => '**'],
        ['word' => '人肉', 'category' => '隐私骚扰', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '肉便器', 'category' => '隐私骚扰', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],

        /* ============================================================
         * 十一、深度补充（高频漏词、网络黑话、拼音缩写、变体写法）
         * ============================================================ */
        // 政治敏感补充
        ['word' => '支那', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '赵家', 'category' => '政治敏感', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],

        // 色情低俗变体补充
        ['word' => '黄网', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => 'BT种子', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '番号', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '无码', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '有码', 'category' => '色情低俗', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '自拍', 'category' => '色情低俗', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],

        // 辱骂攻击变体/拼音缩写补充
        ['word' => '傻b', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '沙比', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '莎比', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '煞逼', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => 'tmd', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => 'tnnd', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => 'wtf', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => 'fuck', 'category' => '辱骂攻击', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '****'],
        ['word' => 'shit', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '****'],
        ['word' => 'bitch', 'category' => '辱骂攻击', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '*****'],
        ['word' => '废物点心', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '****'],

        // 诈骗补充
        ['word' => '刷流量', 'category' => '诈骗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '刷赞', 'category' => '诈骗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '代刷', 'category' => '诈骗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '低价代充', 'category' => '诈骗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],

        /* ============================================================
         * 十二、高精度补充（低误伤、高信号的缺失敏感词）
         * ============================================================ */
        // 辱骂攻击 — 人身侮辱
        ['word' => '狗崽子', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '龟孙', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '龟孙子', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '畜生', 'category' => '辱骂攻击', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '牲口', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '人渣', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '败类', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '杂种', 'category' => '辱骂攻击', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '下贱', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '贱货', 'category' => '辱骂攻击', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '荡妇', 'category' => '辱骂攻击', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '淫妇', 'category' => '辱骂攻击', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '不要脸', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '不要脸的东西', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '******'],
        ['word' => '臭不要脸', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '****'],
        ['word' => '恶心', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '恶心人', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '恶心死了', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '****'],
        // 辱骂攻击 — 英文侮辱
        ['word' => 'asshole', 'category' => '辱骂攻击', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '*******'],
        ['word' => 'ass hole', 'category' => '辱骂攻击', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '********'],
        ['word' => 'motherfucker', 'category' => '辱骂攻击', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***********'],
        ['word' => 'douchebag', 'category' => '辱骂攻击', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '*********'],
        ['word' => 'jackass', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '*******'],
        ['word' => 'dumbass', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '*******'],
        ['word' => 'dipshit', 'category' => '辱骂攻击', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '*******'],
        ['word' => 'bullshit', 'category' => '辱骂攻击', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '********'],
        // 辱骂攻击 — 地域/群体歧视
        ['word' => '蝗虫', 'category' => '辱骂攻击', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '蝗虫人', 'category' => '辱骂攻击', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '白皮猪', 'category' => '辱骂攻击', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '黑鬼', 'category' => '辱骂攻击', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => 'nigger', 'category' => '辱骂攻击', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '******'],
        ['word' => 'nigga', 'category' => '辱骂攻击', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '*****'],
        ['word' => 'chink', 'category' => '辱骂攻击', 'level' => 2, 'match_mode' => 'word', 'replacement' => '*****'],
        // 色情低俗 — 补充
        ['word' => 'SM', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'word', 'replacement' => '**'],
        ['word' => 'pornhub', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '*******'],
        ['word' => 'porn', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'word', 'replacement' => '****'],
        ['word' => 'slut', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'word', 'replacement' => '****'],
        ['word' => 'whore', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'word', 'replacement' => '*****'],
        ['word' => '肛交', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '口交', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '手淫', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '自慰', 'category' => '色情低俗', 'level' => 1, 'match_mode' => 'word', 'replacement' => '**'],
        ['word' => '颜射', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        ['word' => '内射', 'category' => '色情低俗', 'level' => 2, 'match_mode' => 'exact', 'replacement' => '**'],
        // 诈骗 — 补充
        ['word' => '割韭菜', 'category' => '诈骗', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '***'],
        ['word' => '杀猪', 'category' => '诈骗', 'level' => 2, 'match_mode' => 'word', 'replacement' => '**'],
        ['word' => '薅羊毛', 'category' => '诈骗', 'level' => 1, 'match_mode' => 'exact', 'replacement' => '***'],
    ];

    $existing = [];
    foreach ($db->query("SELECT word, match_mode FROM sensitive_words") as $row) {
        $existing[$row['word'] . ':' . $row['match_mode']] = true;
    }

    $insertStmt = $db->prepare("INSERT INTO sensitive_words (word, category, level, match_mode, replacement, enabled) VALUES (:word, :category, :level, :match_mode, :replacement, 1)");
    $added = 0;
    foreach ($defaults as $item) {
        $key = $item['word'] . ':' . $item['match_mode'];
        if (isset($existing[$key])) continue;
        $insertStmt->execute($item);
        $existing[$key] = true;
        $added++;
    }

    if ($added > 0) {
        // 直接清理缓存文件，避免反向依赖 sensitive_filter/helper.php
        $cacheFile = defined('DATA_PATH') ? DATA_PATH . 'cache/sensitive_filter.json' : APP_ROOT . 'data/cache/sensitive_filter.json';
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }
    }

    // 初始化默认白名单（正能量短语，降低误伤）
    init_default_whitelist($db);
}

/**
 * 初始化默认白名单（自动补充缺失词条，可重复执行）
 *
 * 白名单的作用：当文本中包含白名单短语时，短语内出现的敏感词不会被命中。
 * 此处收录的均为含敏感词子串但语义正面的合法短语。
 */
function init_default_whitelist(PDO $db): void {
    // 快速路径：如果白名单表已有数据，跳过耗时的比对初始化
    $hasData = (bool)$db->query("SELECT 1 FROM sensitive_word_whitelist LIMIT 1")->fetchColumn();
    if ($hasData) {
        return;
    }

    $defaults = [
        // 与政治相关词的正能量短语
        '法轮寺',           // 佛教寺庙名（含"法轮"）
        '法轮常转',         // 佛教用语
        '独立思考',         // 鼓励自主思考
        '独立自主',         // 外交方针
        '独立自主和平外交政策',
        '中国独立自主的和平外交政策',
        '反腐败',           // 正面政治行为
        '反腐败倡廉洁',
        '反腐倡廉',
        '人民代表大会制度', // 国家根本政治制度
        '全国人民代表大会',
        '地方各级人民代表大会',
        '人民代表大会',
        '党和国家领导人',   // 正面表述
        '党和国家',
        '共产党宣言',
        '中国共产党章程',
        '中国共产党全国代表大会',
        '中共党史',
        '反霸权主义',
        '反恐怖主义',
        '反分裂国家法',

        // 与违法类词的正能量短语
        '大麻二酚',         // CBD 药物成分（含"大麻"）
        '火麻仁',           // 中药材（含"麻"）
        '天麻',             // 中药材
        '亚麻',             // 植物/纺织材料
        '亚麻籽',
        '胡麻',             // 油料作物
        '罂粟壳',           // 合规中药饮片（含"罂粟"）
        '罂粟籽',           // 食品添加剂
        '枪支管理法',       // 法律法规（含"枪支"）
        '枪支许可证',
        '民用枪支',
        '禁毒日',           // 国际禁毒日（含"禁毒"）
        '国际禁毒日',
        '禁毒宣传',
        '禁毒教育',
        '反吸毒宣传',
        '钓鱼执法',         // 法律术语（含"钓鱼"）
        '钓鱼台',           // 地名
        '钓鱼岛',           // 中国领土
        '钓鱼台群岛',
        '网络信息安全',     // 正面表述（含"网络"）
        '网络安全法',
        '网络强国',
        '洗钱犯罪',         // 法律术语（含"洗钱"）
        '反洗钱法',
        '反洗钱',
        '反传销',
        '反传销宣传',
        '非法集资举报',
        '校园安全教育',

        // 与色情类词的正能量短语
        '性教育',           // 教育领域（含"性"）
        '性健康',
        '性别平等',
        '性别歧视',
        '性侵害防护',
        '防性侵教育',
        '两性健康',
        '性知识科普',
        '青少年性教育',
        '色情信息举报',     // 正面举报行为（含"色情"）
        '色情内容举报',
        '打击色情',
        '扫黄打非',
        '反色情宣传',

        // 与辱骂类词的正能量短语
        '垃圾分类',         // 环保行为（含"垃圾"）
        '垃圾回收',
        '垃圾减量',
        '垃圾处理',
        '电子垃圾',
        '厨余垃圾',
        '废物利用',         // 环保行为（含"废物"）
        '废物回收',
        '工业废物处理',
        '脑残志愿者',       // 关怀残障群体的公益用语
        '关爱脑瘫患者',

        // 与广告诈骗类词的正能量短语
        '反诈骗',
        '反诈骗宣传',
        '防诈骗教育',
        '防诈骗热线',
        '国家反诈中心',
        '反诈骗APP',
        '反洗钱宣传',
        '消费者权益保护',
        '广告法',
        '广告管理条例',
        '广告内容审查',
        '博彩业监管',       // 法律监管（含"博彩"）
        '彩票公益金',
        '福利彩票',
        '体育彩票',
        '中国福利彩票',

        // 与隐私骚扰类词的正能量短语
        '反骚扰',
        '反性骚扰',
        '防治性骚扰',
        '工作场所反骚扰',
        '反恐吓宣传',
        '反勒索',
        '反勒索软件',
        '反敲诈勒索',
        '隐私保护法',
        '个人信息保护法',
        '隐私权保护',
        '数据隐私安全',

        // 与色情类词的合法用法补充
        '丝袜奶茶',       // 饮品名（含"丝袜"）
        '丝袜咖啡',
        '自拍杆',         // 摄影器材（含"自拍"）
        '自拍神器',
        '前置自拍',
        '后置自拍',
        '无码二维码',     // 技术术语（含"无码"）
        '无码支付',
        '番号查询',       // 动画/影视编号（含"番号"）

        // 与辱骂类词的合法用法补充
        '小丑鱼',         // 动物名（含"小丑"）
        '小丑表演',
        '小丑艺术',
        '马戏小丑',
        '奇葩说',         // 综艺节目名（含"奇葩"）
        '奇葩大会',

        // 与诈骗类词的合法用法补充
        '刷脸支付',       // 技术术语（含"刷"）
        '刷脸认证',
        '刷卡',

        // ==========================================
        // 二轮扩充：降低常见误伤，覆盖论坛日常正面用语
        // ==========================================
        // 含"社工"的正能量词
        '社会工作者',
        '社会工作师',
        '社会工作服务站',
        '社会工作专业',
        '社区社会工作者',
        // 含"垃圾"的生活/环保用语
        '垃圾分类指南',
        '垃圾食品',
        '垃圾堆',
        '垃圾清理',
        '太空垃圾',
        '海洋垃圾',
        // 含"自拍"的正面用语
        '自拍教程',
        '自拍摄影',
        '自拍技巧',
        '自拍模式',
        '自拍合影',
        // 含"破防"的游戏/电竞正向用语（破防机制、破防阈值）
        '破防机制',
        '破防阈值',
        '物理破防',
        '法术破防',
        '破防属性',
        // 含"孝子"的正面语境
        '大孝子',
        '孝子贤孙',
        '孝子之心',
        // 含"诱惑"的正面表述
        '抵抗诱惑',
        '抵制诱惑',
        '拒绝诱惑',
        // 含"风骚"的技术/游戏领域夸奖
        '风骚走位',
        '风骚操作',
        '技术风骚',
        // 含"套路"的正面/中性语境
        '反套路',
        '不按套路',
        '新套路',
        // 含"恶心"的客观描述（身体不适等）
        '恶心症状',
        '恶心呕吐',
        '感到恶心',
        '恶心腹泻',
        // 含"渣男/渣女"的正面语境（如反渣教育）
        '反渣男',
        '反渣女',
        // 含"畜生"的自然/学术语境
        '畜生猪',       // 畜牧业
        '畜生道',       // 佛教六道之一
        // 含"薅羊毛"的正规用法（平台优惠、银行活动等合法薅羊毛）
        '薅羊毛攻略',
        '银行薅羊毛',
        '信用卡薅羊毛',
        // 含"杀猪"的非诈骗语境（如传统农业）
        '杀猪菜',
        '杀猪饭',
        '杀猪过年',
        // 含"SM"的非色情语境（如统计学/技术缩写）
        'SM算法',
        'SM协议',
        'SM模块',
        'SM系列',
        // 含"自慰"的合理语境（心理学术语）
        '自我安慰',      // 心理学/生活用语（含"自慰"）
        // 含"小姐"的礼貌称谓（当前不在敏感词中，但预防未来添加）
    ];

    $existing = [];
    foreach ($db->query("SELECT word FROM sensitive_word_whitelist") as $row) {
        $existing[mb_strtolower($row['word'], 'UTF-8')] = true;
    }

    $insertStmt = $db->prepare("INSERT INTO sensitive_word_whitelist (word, enabled) VALUES (:word, 1)");
    $added = 0;
    foreach ($defaults as $word) {
        $key = mb_strtolower($word, 'UTF-8');
        if (isset($existing[$key])) continue;
        $insertStmt->execute([':word' => $word]);
        $existing[$key] = true;
        $added++;
    }

    if ($added > 0) {
        $cacheFile = defined('DATA_PATH') ? DATA_PATH . 'cache/sensitive_filter.json' : APP_ROOT . 'data/cache/sensitive_filter.json';
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }
    }
}

/**
 * 初始化数据库表
 */
function init_db(): void {
    $db = get_db();

    // 核心表由 ensure_core_tables 统一创建
    ensure_core_tables($db);

    // 迁移：为旧表添加新字段
    migrate_column($db, 'users', 'uid', 'INTEGER UNIQUE DEFAULT NULL');
    migrate_column($db, 'users', 'coins', 'INTEGER DEFAULT 0');
    migrate_column($db, 'users', 'posts_count', 'INTEGER DEFAULT 0');
    migrate_column($db, 'users', 'last_active', 'DATETIME');
    migrate_column($db, 'users', 'reset_token', 'VARCHAR(255) DEFAULT NULL');
    migrate_column($db, 'users', 'reset_expires', 'DATETIME DEFAULT NULL');
    migrate_column($db, 'users', 'status', 'VARCHAR(20) DEFAULT \'active\'');
    migrate_column($db, 'users', 'banned_until', 'DATETIME DEFAULT NULL');
    migrate_column($db, 'users', 'muted_until', 'DATETIME DEFAULT NULL');
    migrate_column($db, 'users', 'status_reason', 'TEXT DEFAULT \'\'');
    migrate_column($db, 'users', 'login_fails', 'INTEGER DEFAULT 0');
    migrate_column($db, 'users', 'login_locked_until', 'DATETIME DEFAULT NULL');

    // 补充 role 列（兼容从旧版本升级的安装）
    migrate_column($db, 'users', 'role', "VARCHAR(20) DEFAULT 'user'");
    $db->exec("UPDATE users SET role = 'user' WHERE role IS NULL OR role = ''");

    // 补充索引
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_users_reset_token ON users(reset_token)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_users_remember_token ON users(remember_token)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_users_uid ON users(uid)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_users_role ON users(role)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_users_status ON users(status)");

    // 确保默认数据存在
    ensure_user_groups();
    ensure_default_medals();

    // 为旧用户补充分配 UID
    backfill_user_uids();

    // 补充关键索引（提升用户主页、个人中心、在线统计等查询性能）
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_users_last_active ON users(last_active)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_users_created ON users(created_at DESC)");

    ddl_exec("CREATE INDEX IF NOT EXISTS idx_forums_category ON forums(category_id, display_order)");

    // 迁移：为旧表添加新字段
    migrate_column($db, 'posts', 'forum_id', 'INTEGER DEFAULT NULL');
    migrate_column($db, 'posts', 'is_essence', 'INTEGER DEFAULT 0');
    migrate_column($db, 'posts', 'is_locked', 'INTEGER DEFAULT 0');

    ddl_exec("CREATE INDEX IF NOT EXISTS idx_posts_created ON posts(created_at DESC)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_posts_pinned ON posts(is_pinned DESC, created_at DESC)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_posts_forum ON posts(forum_id, is_pinned DESC, updated_at DESC)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_posts_user ON posts(user_id, created_at DESC)");

    migrate_column($db, 'replies', 'floor', 'INTEGER DEFAULT 0');
    migrate_column($db, 'replies', 'reply_to', 'INTEGER DEFAULT NULL');
    migrate_column($db, 'replies', 'quote_content', 'TEXT DEFAULT NULL');

    ddl_exec("CREATE INDEX IF NOT EXISTS idx_replies_post ON replies(post_id, floor)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_replies_user ON replies(user_id, created_at DESC)");

    // 签到记录表
    ddl_exec("CREATE TABLE IF NOT EXISTS checkins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        checkin_date DATE NOT NULL,
        points INTEGER DEFAULT 0,
        streak INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE(user_id, checkin_date)
    )");

    // 积分/金币流水日志
    ddl_exec("CREATE TABLE IF NOT EXISTS user_points_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        points INTEGER NOT NULL DEFAULT 0,
        coins INTEGER NOT NULL DEFAULT 0,
        type VARCHAR(50) NOT NULL,
        source_type VARCHAR(50) DEFAULT NULL,
        source_id INTEGER DEFAULT NULL,
        description TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_points_log_user ON user_points_log(user_id, created_at DESC)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_points_log_type ON user_points_log(user_id, type, created_at DESC)");

    // 积分等级用户组（Discuz 风格：按积分自动升级）
    ddl_exec("CREATE TABLE IF NOT EXISTS user_groups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(50) UNIQUE NOT NULL,
        display_name VARCHAR(100) NOT NULL,
        min_points INTEGER DEFAULT 0,
        max_points INTEGER DEFAULT NULL,
        color VARCHAR(20) DEFAULT '#6366f1',
        icon VARCHAR(50) DEFAULT 'star',
        display_order INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 权限组
    ddl_exec("CREATE TABLE IF NOT EXISTS roles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(50) UNIQUE NOT NULL,
        display_name VARCHAR(100) NOT NULL,
        permissions TEXT NOT NULL DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    ddl_exec("CREATE TABLE IF NOT EXISTS user_roles (
        user_id INTEGER NOT NULL,
        role_id INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, role_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
    )");

    // 勋章
    ddl_exec("CREATE TABLE IF NOT EXISTS medals (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(50) UNIQUE NOT NULL,
        display_name VARCHAR(100) NOT NULL,
        description TEXT DEFAULT '',
        color VARCHAR(20) DEFAULT '#3b82f6',
        icon VARCHAR(50) DEFAULT 'star',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    ddl_exec("CREATE TABLE IF NOT EXISTS user_medals (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        medal_id INTEGER NOT NULL,
        awarded_by INTEGER DEFAULT NULL,
        awarded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (medal_id) REFERENCES medals(id) ON DELETE CASCADE,
        FOREIGN KEY (awarded_by) REFERENCES users(id) ON DELETE SET NULL,
        UNIQUE(user_id, medal_id)
    )");

    // 公告表
    ddl_exec("CREATE TABLE IF NOT EXISTS announcements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        is_active INTEGER DEFAULT 1,
        display_order INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 站点页面表（用户协议、隐私政策等可编辑页面）
    ddl_exec("CREATE TABLE IF NOT EXISTS site_pages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug VARCHAR(50) UNIQUE NOT NULL,
        title VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 站内信表
    ddl_exec("CREATE TABLE IF NOT EXISTS pm_conversations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user1_id INTEGER NOT NULL,
        user2_id INTEGER NOT NULL,
        last_message_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user1_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (user2_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE(user1_id, user2_id)
    )");

    ddl_exec("CREATE TABLE IF NOT EXISTS pm_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        conversation_id INTEGER NOT NULL,
        sender_id INTEGER NOT NULL,
        content TEXT NOT NULL,
        is_read INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (conversation_id) REFERENCES pm_conversations(id) ON DELETE CASCADE,
        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    ddl_exec("CREATE INDEX IF NOT EXISTS idx_pm_messages_conv ON pm_messages(conversation_id, created_at)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_pm_conv_user1 ON pm_conversations(user1_id)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_pm_conv_user2 ON pm_conversations(user2_id)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_pm_messages_unread ON pm_messages(is_read, sender_id) WHERE is_read = 0");

    // 收藏表
    ddl_exec("CREATE TABLE IF NOT EXISTS favorites (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        post_id INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
        UNIQUE(user_id, post_id)
    )");

    // 举报表
    ddl_exec("CREATE TABLE IF NOT EXISTS reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER DEFAULT NULL,
        reply_id INTEGER DEFAULT NULL,
        reporter_id INTEGER NOT NULL,
        reason_type VARCHAR(50) NOT NULL DEFAULT 'other',
        reason TEXT DEFAULT '',
        status VARCHAR(20) DEFAULT 'pending',
        admin_note TEXT DEFAULT '',
        handled_by INTEGER DEFAULT NULL,
        handled_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
        FOREIGN KEY (reply_id) REFERENCES replies(id) ON DELETE CASCADE,
        FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_reports_status ON reports(status, created_at DESC)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_reports_reporter ON reports(reporter_id, created_at DESC)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_reports_target ON reports(reply_id, status)");

    // 消息通知表
    ddl_exec("CREATE TABLE IF NOT EXISTS notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        type VARCHAR(50) NOT NULL DEFAULT 'reply',
        title VARCHAR(255) NOT NULL,
        content TEXT DEFAULT '',
        link VARCHAR(500) DEFAULT NULL,
        is_read INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id, is_read, created_at DESC)");

    // 邮件发送日志表（用于邮件中心统计）
    ddl_exec("CREATE TABLE IF NOT EXISTS mail_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        recipient VARCHAR(255) NOT NULL,
        recipient_name VARCHAR(100) DEFAULT '',
        subject VARCHAR(255) NOT NULL DEFAULT '',
        type VARCHAR(50) NOT NULL DEFAULT 'other',
        status VARCHAR(20) NOT NULL DEFAULT 'success',
        error_message TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_mail_logs_created ON mail_logs(created_at DESC)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_mail_logs_status ON mail_logs(status, created_at DESC)");
    ddl_exec("CREATE INDEX IF NOT EXISTS idx_mail_logs_type ON mail_logs(type, created_at DESC)");

    // 初始化默认积分等级组
    $defaultGroups = [
        ['newbie', '新手上路', 0, 49, '#94a3b8', 'seedling'],
        ['member', '初级会员', 50, 199, '#6366f1', 'zap'],
        ['senior', '中级会员', 200, 999, '#10b981', 'award'],
        ['advanced', '高级会员', 1000, 4999, '#2563eb', 'star'],
        ['veteran', '资深会员', 5000, 9999, '#8b5cf6', 'diamond'],
        ['legend', '论坛元老', 10000, NULL, '#ef4444', 'crown'],
    ];
    foreach ($defaultGroups as $g) {
        $stmt = ddl_prepare($db, "INSERT OR IGNORE INTO user_groups (name, display_name, min_points, max_points, color, icon, display_order) VALUES (:name, :display_name, :min, :max, :color, :icon, :order)");
        $stmt->execute([':name' => $g[0], ':display_name' => $g[1], ':min' => $g[2], ':max' => $g[3], ':color' => $g[4], ':icon' => $g[5], ':order' => $g[2]]);
    }

    // 更新默认图标：资深会员与论坛元老不应相同
    ddl_exec("UPDATE OR IGNORE user_groups SET icon = 'diamond' WHERE name = 'veteran' AND icon = 'crown'");
    ddl_exec("UPDATE OR IGNORE user_groups SET icon = 'crown' WHERE name = 'legend' AND icon != 'crown'");

    // 迁移：将旧版橙色等级颜色更新为新版靛蓝色
    ddl_exec("UPDATE OR IGNORE user_groups SET color = '#2563eb' WHERE name = 'advanced' AND color IN ('#f59e0b', '#f97316', '#ea580c')");

    // 初始化默认权限组
    $defaultRoles = [
        ['moderator', '版主', 'manage_posts,manage_replies,manage_users,manage_forums'],
        ['vip', 'VIP用户', ''],
        // 社区管理员：两级管理员体系的内置角色（带后台准入但不含超管专属权限）
        ['community_admin', '社区管理员', 'admin_access,manage_posts,manage_replies,manage_reports,manage_ban_appeals,manage_user_dispose'],
    ];
    foreach ($defaultRoles as $role) {
        $stmt = ddl_prepare($db, "INSERT OR IGNORE INTO roles (name, display_name, permissions) VALUES (:name, :display_name, :permissions)");
        $stmt->execute([':name' => $role[0], ':display_name' => $role[1], ':permissions' => $role[2]]);
    }

    // 初始化默认版块分类和版块
    $count = (int)$db->query("SELECT COUNT(*) FROM forum_categories")->fetchColumn();
    if ($count === 0) {
        $db->exec("INSERT INTO forum_categories (name, display_order) VALUES ('技术交流', 0)");
        $cat1 = (int)$db->lastInsertId();
        $db->exec("INSERT INTO forum_categories (name, display_order) VALUES ('生活娱乐', 1)");
        $cat2 = (int)$db->lastInsertId();
        $db->exec("INSERT INTO forum_categories (name, display_order) VALUES ('站务管理', 2)");
        $cat3 = (int)$db->lastInsertId();

        $defaultForums = [
            [$cat1, '后端开发', 'PHP、Python、Java、Go 等后端技术讨论', 'code', 0],
            [$cat1, '前端开发', 'HTML/CSS/JS、Vue、React 等前端技术讨论', 'design', 1],
            [$cat1, '数据库', 'MySQL、SQLite、Redis 等数据库讨论', 'database', 2],
            [$cat2, '闲聊灌水', '日常闲聊、灌水交友', 'chat', 0],
            [$cat2, '资源分享', '好文好书好工具分享', 'gift', 1],
            [$cat3, '站点公告', '论坛官方公告与动态', 'announcement', 0],
            [$cat3, '意见反馈', '论坛建议与问题反馈', 'help', 1],
        ];
        $stmt = $db->prepare("INSERT INTO forums (category_id, name, description, icon, display_order) VALUES (:cat, :name, :desc, :icon, :order)");
        foreach ($defaultForums as $f) {
            $stmt->execute([':cat' => $f[0], ':name' => $f[1], ':desc' => $f[2], ':icon' => $f[3], ':order' => $f[4]]);
        }
    }

    // 迁移：将旧版 emoji 图标转换为关键字符串
    $emojiToKey = [
        '💬' => 'chat', '🔧' => 'tool', '🎨' => 'design', '💾' => 'database',
        '📚' => 'book', '☕' => 'coffee', '💡' => 'lightbulb', '📢' => 'announcement',
        '🔥' => 'fire', '⭐' => 'star', '🎮' => 'game', '🎬' => 'film',
        '📷' => 'camera', '⚽' => 'sport', '📝' => 'pen', '💻' => 'desktop',
        '🌐' => 'globe', '🚀' => 'rocket', '❓' => 'help', '🎉' => 'party',
        '📁' => 'folder', '📂' => 'folder', '📨' => 'mail', '📧' => 'mail',
        '🔔' => 'bell', '⚙️' => 'cog', '🎵' => 'music', '🎶' => 'music',
        '☁️' => 'cloud', '🌤️' => 'cloud', '🛡️' => 'shield', '🔒' => 'shield',
        '❤️' => 'heart', '💝' => 'gift', '🎁' => 'gift', '👥' => 'users',
        '🧠' => 'cpu', '📠' => 'cpu', '🗺️' => 'map', '📍' => 'map',
        '🚩' => 'flag', '🔖' => 'bookmark', '🏆' => 'star', '⚡' => 'fire',
        '🛠️' => 'tool', '✏️' => 'pen', '✉️' => 'mail', '🔭' => 'camera',
        '🥱' => 'coffee', '😀' => 'chat', '🙂' => 'chat', '😊' => 'chat',
    ];
    $migrateStmt = $db->prepare("UPDATE forums SET icon = :key WHERE icon = :emoji");
    foreach ($emojiToKey as $emoji => $key) {
        $migrateStmt->execute([':key' => $key, ':emoji' => $emoji]);
    }

    // 初始化默认公告
    $annCount = (int)$db->query("SELECT COUNT(*) FROM announcements")->fetchColumn();
    if ($annCount === 0) {
        $db->exec("INSERT INTO announcements (title, content, is_active, display_order) VALUES ('欢迎来到云界论坛', '云界论坛正式开站！欢迎大家注册交流。', 1, 0)");
    }

    // 初始化默认站点页面（用户协议、隐私政策）
    init_default_site_pages($db);

    // 补齐扩展表：ban_appeals / sensitive_words / password_reset_requests 平时由
    // auto_migrate 的 ensure_* 兜底创建，但若迁移版本锁已命中（例如切换数据库后
    // 残留旧 db_version.lock），auto_migrate 会跳过全量 DDL，导致后台相关页面
    // 报"表不存在"。此处显式创建，保证全新安装后所有表完整可用。
    ensure_ban_appeals_table($db);
    ensure_sensitive_words_tables($db);
    init_default_sensitive_words($db);
    ensure_password_reset_requests_table($db);
}

/**
 * 初始化默认站点页面（用户协议、隐私政策）
 */
function init_default_site_pages(PDO $db): void {
    // 获取已存在的 slug 列表，只插入缺失的页面
    $existingSlugs = $db->query("SELECT slug FROM site_pages")->fetchAll(PDO::FETCH_COLUMN);

    $pages = [
        'terms' => [
            'title'   => '用户协议',
            'content' => '<p>欢迎您使用 ' . e(SITE_NAME) . '（以下简称"本论坛"）。请您仔细阅读以下用户协议内容。当您完成注册或登录流程时，即视为您已充分阅读、理解并同意接受本协议的全部条款。</p>
<h2>一、账号注册与管理</h2>
<ol>
<li>用户应当使用真实、准确、完整的个人信息进行注册，并对所提供信息的真实性负责。</li>
<li>用户注册成功后，将获得本论坛账号及相应的使用权。用户应妥善保管账号、密码及其他登录信息，对账号下的一切行为承担法律责任。</li>
<li>用户不得将账号转让、出借、出租或以其他方式允许第三方使用。如发现账号被盗用或存在安全风险，应立即通知本论坛管理员。</li>
<li>本论坛有权根据相关法律法规及平台管理需要，对违规账号采取警告、限制功能、封禁直至删除账号等措施。</li>
</ol>
<h2>二、用户行为规范</h2>
<ol>
<li>用户应遵守中华人民共和国法律法规，遵守社会公德，尊重网络道德。</li>
<li>禁止发布、传播以下违法违规内容：
<ul>
<li>反对宪法所确定的基本原则的；</li>
<li>危害国家安全、泄露国家秘密、颠覆国家政权、破坏国家统一的；</li>
<li>损害国家荣誉和利益的；</li>
<li>煽动民族仇恨、民族歧视，破坏民族团结的；</li>
<li>破坏国家宗教政策，宣扬邪教和封建迷信的；</li>
<li>散布谣言，扰乱社会秩序，破坏社会稳定的；</li>
<li>散布淫秽、色情、赌博、暴力、凶杀、恐怖或者教唆犯罪的；</li>
<li>侮辱或者诽谤他人，侵害他人合法权益的；</li>
<li>含有法律、行政法规禁止的其他内容的。</li>
</ul>
</li>
<li>禁止利用本论坛进行任何形式的商业广告、垃圾信息传播、恶意刷屏、刷量、灌水等行为。</li>
<li>禁止未经授权收集、复制、修改、传播其他用户的个人信息或本论坛的内容数据。</li>
</ol>
<h2>三、内容发布与知识产权</h2>
<ol>
<li>用户在本论坛发布的内容，包括但不限于帖子、回复、评论、图片等，均视为用户本人创作或已获得合法授权。</li>
<li>用户授予本论坛免费的、非独家的、可转授权的使用权，用于内容的展示、推广、存档及为维护平台秩序所必需的处理。</li>
<li>用户应确保发布内容不侵犯任何第三方的知识产权、肖像权、名誉权等合法权益。如有侵权行为，由发布者自行承担法律责任。</li>
<li>本论坛有权根据投诉或管理需要，对涉嫌侵权或违规内容进行删除、屏蔽、下架等处理，且无需事先通知发布者。</li>
</ol>
<h2>四、隐私与个人信息保护</h2>
<ol>
<li>本论坛重视用户个人信息保护，具体保护方式请参见《隐私政策》。</li>
<li>用户同意本论坛在法律法规允许的范围内，收集、使用、存储和保护其个人信息。</li>
</ol>
<h2>五、免责声明</h2>
<ol>
<li>本论坛尽力提供稳定、安全的服务，但不对服务的连续性、及时性、安全性作出绝对保证。</li>
<li>对于因不可抗力、系统维护、网络故障、第三方原因等导致的服务中断或数据丢失，本论坛不承担赔偿责任，但将尽力减少损失。</li>
<li>本论坛对用户之间因使用本服务而产生的纠纷不承担责任，用户应自行协商解决或通过法律途径处理。</li>
</ol>
<h2>六、协议变更与终止</h2>
<ol>
<li>本论坛有权根据法律法规变化及平台运营需要，随时修改本协议。修改后的协议将在本页面公布，公布后即时生效。</li>
<li>如用户不同意修改后的协议内容，应停止使用本论坛服务并注销账号。</li>
<li>用户违反本协议或相关法律法规的，本论坛有权暂停或终止向用户提供服务。</li>
</ol>
<h2>七、其他</h2>
<ol>
<li>本协议的订立、执行、解释及争议解决均适用中华人民共和国法律。</li>
<li>如本协议任何条款被认定为无效或不可执行，不影响其他条款的效力。</li>
<li>如您对本协议有任何疑问，可通过站内信或管理员邮箱与我们联系。</li>
</ol>',
        ],
        'privacy' => [
            'title'   => '隐私政策',
            'content' => '<p>' . e(SITE_NAME) . '（以下简称"本论坛"）非常重视用户的隐私和个人信息保护。本隐私政策将帮助您了解我们如何收集、使用、存储、共享和保护您的个人信息，以及您享有的相关权利。</p>
<h2>一、我们收集的信息</h2>
<ol>
<li><strong>账号信息：</strong>当您注册账号时，我们会收集您的用户名、邮箱地址、密码（加密存储）等基本信息。</li>
<li><strong>个人资料信息：</strong>您可以选择填写头像、个性签名、个人简介等资料信息，这些信息将用于展示您的个人主页。</li>
<li><strong>内容信息：</strong>您在本论坛发布的帖子、回复、评论、点赞、收藏、私信等内容数据。</li>
<li><strong>设备与日志信息：</strong>为保障服务安全与稳定，我们会收集您的 IP 地址、浏览器类型、操作系统、访问时间、操作日志等技术信息。</li>
<li><strong>Cookie 与本地存储：</strong>我们使用 Cookie 和类似技术来记录您的登录状态、偏好设置及提升用户体验。您可以在浏览器设置中管理 Cookie。</li>
</ol>
<h2>二、我们如何使用信息</h2>
<ol>
<li>用于账号注册、登录验证、身份识别及密码找回等核心服务。</li>
<li>用于向您展示个性化内容、推荐感兴趣的版块或帖子。</li>
<li>用于维护论坛秩序，识别和处理违规行为、垃圾信息及安全风险。</li>
<li>用于改进服务质量、进行数据分析及故障排查。</li>
<li>用于在获得您同意或法律法规允许的情况下，向您发送通知、公告或服务相关信息。</li>
</ol>
<h2>三、信息的存储与保护</h2>
<ol>
<li>我们会采取合理的技术措施和管理措施保护您的个人信息，防止数据泄露、损毁或丢失。</li>
<li>您的密码经过加密处理后存储，我们不会以明文形式保存您的密码。</li>
<li>我们会根据服务需要和法律法规要求，在合理期限内保留您的个人信息。超出保留期限后，我们会对个人信息进行删除或匿名化处理。</li>
</ol>
<h2>四、信息的共享与披露</h2>
<ol>
<li>我们不会向任何第三方出售、出租或交易您的个人信息。</li>
<li>在以下情形中，我们可能会披露您的个人信息：
<ul>
<li>获得您的明确同意；</li>
<li>根据法律法规、司法机关或行政机关的强制性要求；</li>
<li>为保护本论坛、其他用户或公众的合法权益所必需；</li>
<li>为处理纠纷、投诉或安全事件所必需。</li>
</ul>
</li>
</ol>
<h2>五、您的权利</h2>
<ol>
<li><strong>访问与更正：</strong>您可以登录账号，在个人中心查看和修改您的个人信息。</li>
<li><strong>删除：</strong>在符合法律法规规定的情况下，您可以申请删除您的账号及相关个人信息。</li>
<li><strong>撤回同意：</strong>您可以随时通过账号设置或联系我们，撤回对特定信息处理的同意。</li>
<li><strong>注销账号：</strong>您有权申请注销账号。注销后，我们将按照法律法规要求处理您的个人信息。</li>
</ol>
<h2>六、Cookie 政策</h2>
<ol>
<li>我们使用会话 Cookie 来维持您的登录状态，使用持久 Cookie 来实现"记住我"功能。</li>
<li>您可以通过浏览器设置清除或拒绝 Cookie，但这样可能会影响您使用本论坛的部分功能。</li>
</ol>
<h2>七、未成年人保护</h2>
<ol>
<li>本论坛不面向未满 18 周岁的未成年人提供服务。若我们发现收集了未成年人的个人信息，将尽快删除。</li>
</ol>
<h2>八、政策更新</h2>
<ol>
<li>我们可能会根据法律法规变化或服务调整，适时更新本隐私政策。更新后的政策将在本页面公布，公布后即时生效。</li>
<li>如您不同意更新后的政策内容，应停止使用本论坛服务。</li>
</ol>
<h2>九、联系我们</h2>
<ol>
<li>如您对本隐私政策或个人信息处理有任何疑问、意见或投诉，可通过站内信或管理员邮箱与我们联系。</li>
</ol>',
        ],
        'disclaimer' => [
            'title'   => '免责声明',
            'content' => '<p>' . e(SITE_NAME) . '（以下简称"本论坛"）在此特别声明如下免责及责任限制条款。访问或使用本论坛即视为您已充分阅读、理解并同意接受本免责声明的全部内容。</p>
<h2>一、内容免责</h2>
<ol>
<li>本论坛上的内容（包括但不限于帖子、回复、评论、图片、链接等）均由用户自行发布，仅代表发布者个人观点，与本论坛立场无关。</li>
<li>本论坛不对用户发布内容的真实性、准确性、完整性、合法性作任何明示或默示的保证。用户应自行判断内容的可信度并承担依赖该内容所产生的风险。</li>
<li>如您发现本论坛上存在侵犯您合法权益的内容，请通过站内信或管理员邮箱联系我们。我们将在收到有效通知后依法及时处理。</li>
</ol>
<h2>二、服务免责</h2>
<ol>
<li>本论坛按"现状"提供服务，不提供任何形式的明示或默示担保，包括但不限于适销性、特定用途适用性、不侵权等担保。</li>
<li>本论坛尽力保证服务的连续性和安全性，但不对以下情形承担责任：
<ul>
<li>因不可抗力、系统维护、网络故障、电力中断、第三方服务故障等导致的服务中断或数据丢失；</li>
<li>因计算机病毒、木马、恶意软件或黑客攻击造成的任何损失；</li>
<li>因用户自身设备故障、网络环境、操作不当等原因导致的服务不可用。</li>
</ul>
</li>
</ol>
<h2>三、链接免责</h2>
<ol>
<li>本论坛可能包含指向第三方网站或资源的链接。这些链接仅为用户方便而提供，不构成本论坛对第三方内容的认可或推荐。</li>
<li>本论坛对第三方网站或资源的可用性、内容、产品或服务不承担任何责任。用户访问第三方网站应自行承担风险。</li>
</ol>
<h2>四、用户行为与赔偿责任</h2>
<ol>
<li>用户应对其在本论坛上的所有行为承担法律责任。用户因违反法律法规或本论坛用户协议而给本论坛或第三方造成损失的，应承担相应的赔偿责任。</li>
<li>用户之间因使用本论坛服务产生的任何纠纷，应由双方自行协商解决，本论坛不承担调解或赔偿责任。但本论坛保留在必要时介入处理的权利。</li>
</ol>
<h2>五、知识产权免责</h2>
<ol>
<li>用户在发布内容时应确保拥有该内容的合法权利或已获得充分授权。如用户发布的内容侵犯第三方知识产权，由该用户自行承担全部法律责任。</li>
<li>本论坛上的用户发布内容，其知识产权归原作者或合法权利人所有。本论坛平台本身（包括代码、设计、标识等）的知识产权归本论坛运营方所有。</li>
</ol>
<h2>六、法律适用与管辖</h2>
<ol>
<li>本免责声明的订立、执行、解释及争议解决均适用中华人民共和国法律。</li>
<li>如本声明任何条款被认定为无效或不可执行，不影响其他条款的效力。</li>
<li>因本声明引起的或与本声明有关的任何争议，双方应首先友好协商解决；协商不成的，任何一方均可向本论坛运营方所在地有管辖权的人民法院提起诉讼。</li>
</ol>',
        ],
        'service' => [
            'title'   => '服务协议',
            'content' => '<p>欢迎使用' . e(SITE_NAME) . '（以下简称"本论坛"）。在注册账号并使用本论坛服务前，请您仔细阅读并充分理解本服务协议（以下简称"本协议"）的全部内容。</p>
<h2>一、协议接受</h2>
<ol>
<li>本协议是您与本论坛运营方之间关于您使用本论坛服务所订立的协议。</li>
<li>您通过注册、登录或以任何方式使用本论坛服务的行为，即视为您已阅读并同意本协议的全部条款。</li>
<li>如您不同意本协议任何条款，请立即停止注册流程并停止使用本论坛服务。</li>
</ol>
<h2>二、账号注册与管理</h2>
<ol>
<li>您在注册时应提供真实、准确、完整的个人信息，并在信息变更时及时更新。</li>
<li>您应妥善保管账号和密码，对通过您账号进行的所有活动承担法律责任。如发现账号被盗用或存在安全漏洞，应立即通知管理员。</li>
<li>每个用户仅允许注册一个账号。禁止注册多个账号、冒用他人信息注册或转让、出借账号。</li>
<li>您不得利用任何技术手段绕过或干扰本论坛正常的用户注册流程。</li>
</ol>
<h2>三、服务内容</h2>
<ol>
<li>本论坛为用户提供在线交流、信息分享、资源下载等社区服务，具体以本论坛实际提供为准。</li>
<li>本论坛有权在不事先通知的情况下，对服务内容进行变更、升级或暂停。如因系统维护或升级需暂停服务，本论坛将尽可能提前公告。</li>
<li>本论坛不对服务的及时性、安全性、准确性作任何形式的保证，但将尽合理努力保障服务的正常运行。</li>
</ol>
<h2>四、用户行为规范</h2>
<ol>
<li>您承诺不会利用本论坛从事任何违法违规活动，包括但不限于：<ul>
<li>发布危害国家安全、破坏民族团结、宣扬暴力恐怖、煽动颠覆国家政权的内容；</li>
<li>传播淫秽色情、赌博、毒品等违法犯罪信息；</li>
<li>发布诽谤、侮辱、辱骂、人身攻击等侵犯他人合法权益的言论；</li>
<li>散布计算机病毒、木马、恶意代码或实施网络攻击；</li>
<li>发布垃圾广告、传销信息或进行非法商业推广；</li>
<li>侵犯他人知识产权、商业秘密或隐私权。</li>
</ul></li>
<li>您同意遵守本论坛的各项管理规则和版块规定，接受管理员和版主的合法管理。</li>
<li>本论坛有权根据相关法律法规和本协议，对违规内容和账号采取删除内容、警告、禁言、封禁账号等措施。</li>
</ol>
<h2>五、内容权利</h2>
<ol>
<li>您在本论坛发布的内容（包括但不限于文字、图片、链接等），其知识产权归您或合法权利人所有。</li>
<li>您授予本论坛在全球范围内非独家的、免费的、不可撤销的使用权，包括但不限于存储、展示、复制、分发您发布的内容。</li>
<li>您应确保您发布的内容未侵犯任何第三方的合法权益。如因您发布的内容引发任何纠纷或法律责任，由您自行承担。</li>
</ol>
<h2>六、知识产权</h2>
<ol>
<li>本论坛平台（包括但不限于程序代码、界面设计、Logo、数据库结构等）的知识产权归本论坛运营方所有，受法律保护。</li>
<li>未经本论坛运营方书面许可，任何人不得对本论坛平台进行反向工程、破解、修改、复制或用于商业目的。</li>
</ol>
<h2>七、隐私保护</h2>
<ol>
<li>本论坛重视您的个人信息安全。您的个人信息将按照《隐私政策》的规定进行收集、使用和保护。</li>
<li>除法律法规规定或经您同意外，本论坛不会向任何第三方提供您的个人信息。</li>
</ol>
<h2>八、免责条款</h2>
<ol>
<li>本论坛仅为用户提供信息交流平台，不对用户发布内容的真实性、准确性、合法性承担责任。</li>
<li>用户之间因使用本论坛发生纠纷的，应自行协商解决，本论坛不承担调解或赔偿义务。</li>
<li>因不可抗力、系统故障、第三方服务等原因导致的服务中断或数据丢失，本论坛不承担责任。</li>
</ol>
<h2>九、协议修改</h2>
<ol>
<li>本论坛有权对本协议条款进行修改。修改后的协议将在本论坛公布后生效。</li>
<li>如您不同意修改后的协议，应停止使用本论坛服务。继续使用即视为同意接受修改后的协议。</li>
</ol>
<h2>十、法律适用</h2>
<ol>
<li>本协议的订立、执行、解释及争议解决均适用中华人民共和国法律。</li>
<li>如本协议任何条款被认定为无效或不可执行，不影响其他条款的效力。</li>
</ol>',
        ],
    ];

    $stmt = $db->prepare("INSERT INTO site_pages (slug, title, content, updated_at) VALUES (:slug, :title, :content, CURRENT_TIMESTAMP)");
    foreach ($pages as $slug => $page) {
        if (in_array($slug, $existingSlugs, true)) continue;  // 跳过已存在的页面
        $stmt->execute([':slug' => $slug, ':title' => $page['title'], ':content' => $page['content']]);
    }
}

/**
 * 获取站点页面内容（带缓存）
 */
function get_site_page(string $slug): ?array {
    $stmt = get_db()->prepare("SELECT * FROM site_pages WHERE slug = :slug");
    $stmt->execute([':slug' => $slug]);
    return $stmt->fetch() ?: null;
}

/**
 * 获取所有站点页面列表（管理后台用）
 */
function get_all_site_pages(): array {
    $stmt = get_db()->query("SELECT * FROM site_pages ORDER BY id ASC");
    return $stmt->fetchAll();
}

/**
 * 更新站点页面内容
 */
function update_site_page(string $slug, string $title, string $content): bool {
    $stmt = get_db()->prepare("UPDATE site_pages SET title = :title, content = :content, updated_at = CURRENT_TIMESTAMP WHERE slug = :slug");
    return $stmt->execute([':title' => $title, ':content' => $content, ':slug' => $slug]);
}

/**
 * 获取表的列名列表（带缓存，避免重复 DESCRIBE）
 */
function get_table_columns(PDO $db, string $table): array {
    static $cache = [];
    if (!isset($cache[$table])) {
        $info = get_db_driver()->getTableInfo($table);
        $cache[$table] = array_column($info, 'name');
    }
    return $cache[$table];
}

/**
 * 迁移：为表添加列（如果不存在）
 */
function migrate_column(PDO $db, string $table, string $column, string $definition): void {
    try {
        $columns = get_table_columns($db, $table);
        if (!in_array($column, $columns, true)) {
            $sql = "ALTER TABLE $table ADD COLUMN $column $definition";
            $db->exec(get_db_driver()->translateDDL($sql));
        }
    } catch (Exception $e) {
        error_log('列迁移失败: ' . $table . '.' . $column . ' - ' . $e->getMessage());
    }
}

// ==================== 安装日志（用于问题排查） ====================

/**
 * 安装 DDL 日志条目
 */
function ddl_install_log(string $status, string $originalSql, string $translatedSql, float $elapsedMs, ?string $error = null): void {
    if (!isset($GLOBALS['_ddl_install_log'])) {
        $GLOBALS['_ddl_install_log'] = [];
    }
    // 提取表名用于分类
    $tableName = '';
    if (preg_match('/TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $originalSql, $m)) {
        $tableName = $m[1];
    } elseif (preg_match('/INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $originalSql, $m)) {
        $tableName = 'idx:' . $m[1];
    } elseif (preg_match('/^(INSERT|UPDATE)\s/i', $originalSql)) {
        $tableName = 'INSERT';
    }

    $GLOBALS['_ddl_install_log'][] = [
        'status'     => $status,
        'table'      => $tableName,
        'sql_orig'   => $originalSql,
        'sql_trans'   => $translatedSql,
        'elapsed_ms' => round($elapsedMs * 1000, 2),
        'error'      => $error,
    ];
}

/**
 * 获取安装日志
 * @return array
 */
function get_ddl_install_log(): array {
    return $GLOBALS['_ddl_install_log'] ?? [];
}

/**
 * 清空安装日志
 */
function clear_ddl_install_log(): void {
    $GLOBALS['_ddl_install_log'] = [];
}
