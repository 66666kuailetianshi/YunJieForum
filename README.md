# 云界论坛 (Cloud Forum)

> **当前状态：Beta 测试版**（`v1.3.7-beta`）｜ 轻量级社区论坛系统 · PHP + SQLite · 开箱即用

**[English](README.en.md) · [繁體中文](README.zh-TW.md)**

`云界论坛` 是一套纯 PHP 编写的轻量级社区论坛（BBS）系统，默认使用 SQLite 文件数据库，无需独立部署数据库服务器即可运行，适合个人博客社区、兴趣小组、内网知识库等场景。系统内置用户体系、版块/帖子/回复、私信、通知、签到积分、勋章角色、内容审核（敏感词）、邮件与流量统计等完整功能，并提供可视化的安装向导与后台管理。

- **当前版本：** `1.3.7-beta`
- **开发语言：** PHP 7.4+
- **默认数据库：** SQLite（同时支持 MySQL / PostgreSQL）
- **前端：** 原生 HTML + CSS + 少量原生 JS，无前端构建步骤
- **多语言：** 简体中文 / 繁體中文 / English

---

## 目录

- [1. 核心特性](#1-核心特性)
- [2. 技术架构](#2-技术架构)
- [3. 目录结构](#3-目录结构)
- [4. 环境要求](#4-环境要求)
- [5. 安装与部署](#5-安装与部署)
- [6. 配置说明](#6-配置说明)
- [7. 路由与访问方式](#7-路由与访问方式)
- [8. 数据库设计](#8-数据库设计)
- [9. 前台功能](#9-前台功能)
- [10. 后台管理](#10-后台管理)
- [11. API 接口](#11-api-接口)
- [12. 主题与定制](#12-主题与定制)
- [13. 安全机制](#13-安全机制)
- [14. 二次开发指南](#14-二次开发指南)
- [15. 数据备份与维护](#15-数据备份与维护)
- [16. 常见问题 (FAQ)](#16-常见问题-faq)

---

## 1. 核心特性

| 模块 | 说明 |
| --- | --- |
| 用户系统 | 注册、登录、记住密码、个人资料、修改密码、邮箱验证码（需 SMTP）、找回密码、强制改密、封禁申诉 |
| 内容体系 | 多级版块（分类 → 版块）、主题帖、回复（楼中楼/引用）、置顶、加精、锁帖、收藏、搜索 |
| 积分 / 等级 | 发帖/回复/收回复/被收藏获得积分与金币，每日奖励上限防刷；按积分自动划分**用户组**与头衔；**勋章**系统 |
| 签到 | 每日签到得积分+金币，连续签到递增奖励，7/30 天里程碑额外奖励 |
| 社交互动 | 站内私信（PM，前端轮询实时提醒）、系统通知、@ 提醒 |
| 权限 / 角色 | 基于 `roles` 的权限组（`has_permission`），支持超级管理员、版主等；与按积分自动晋升的**用户组**分离 |
| 内容审核 | 敏感词过滤引擎（Trie + Aho-Corasick），支持精确/整词/正则三种匹配、白名单、三级处理（替换 / 拦截 / 人工审核）、命中日志；用户举报、封禁申诉、禁言 |
| 邮件 | 原生 `fsockopen` 实现的 SMTP 发送器（无第三方依赖），支持 SSL/TLS，邮件日志、退信处理（bounce）、邮件统计与通知 |
| 运维监控 | 流量统计（访问记录）、系统状态（CPU/内存/温度/网络/磁盘/显卡，详见[第 10.1 节](#101-系统状态监控-system_status)）、数据库备份、自动 Schema 迁移、安装/错误日志 |
| 系统更新 | 「系统更新中心」支持手动/自动检查并应用版本更新：下载 → 校验 SHA256 哈希 → 自动备份 → 覆盖升级，详见[第 10.3 节](#103-系统更新中心-update_center) |
| 多语言 | 内置 `简体中文 / 繁體中文 / English`，按 URL、Cookie、配置、浏览器语言自动识别 |
| 主题 | 基于 CSS 变量的明暗双主题（light / dark），可改色与换肤 |
| 人机验证 | 内置「滑块拼图」「点选文字」「推理交换」与「图片字母」四种模式人机验证，支持行为打分、难度调节（简单/普通/困难）、触发模式（始终显示/可疑触发/高风险触发）与显示方式（内嵌式/弹窗式/触发式），GD 生成背景图，无需第三方服务 |

---

## 2. 技术架构

系统采用**单一入口 + 前端控制器**模式，无 MVC 框架，全部基于原生 PHP，结构清晰、易于部署。

```
浏览器请求
   │
   ▼
index.php  ── 路由解析（route / s / REQUEST_URI）→ 分发
   │
   ├── /admin/*           → app/admin/controllers/*.php  +  app/admin/api/*.php
   ├── /install           → install.php（安装向导）
   ├── /api/*             → public/api/*.php（公共 JSON 接口）
   └── 前台路由            → app/controllers/*.php（页面）
                              │
                              ├── app/includes/functions.php（全局函数/工具）
                              ├── app/includes/db.php（Schema/迁移/初始化）
                              ├── app/includes/database/*（数据库驱动抽象）
                              └── app/includes/mailer.php / bounce_processor.php

配置：data/site_config.php（安装生成，含 DB_* / SITE_* / SMTP_* 常量）
数据：data/forum.db（SQLite）/ 远程数据库
运行时：data/error.log、data/db_version.lock、uploads/*
```

**数据库抽象层**（`app/includes/database/`）：

- `DatabaseFactory`：根据配置创建驱动（`sqlite` / `mysql` / `pgsql`）。
- `AbstractDriver`：封装 PDO，提供跨库兼容的查询辅助方法，并实现重连等机制。
- `SQLiteDriver` / `MySQLDriver` / `PostgreSQLDriver`：各库特定实现（连接串、PRAGMA/SET NAMES、类型与分页差异）。
- 全局通过 `get_db()` 获取 PDO、`get_db_driver()` 获取驱动实例。安装向导中的 DDL 会经过**方言翻译**以适配不同数据库。

---

## 3. 目录结构

> 下方树为**实测绘得**的项目布局（`app/` 含 121 个 PHP 文件 + 各资源文件）。带 `#` 注释的为本项目实际存在的文件。

```
云界论坛/
├── index.php                  # 前台入口 / 路由分发（单一入口，解析 route/s/REQUEST_URI）
├── install.php               # 安装向导（数据库→环境检测→站点配置→完成，前置语言与授权协议）
├── LICENSE                    # 开源许可证文本
├── README.md / README.en.md / README.zh-TW.md   # 三语项目文档
│
├── app/                       # 应用核心（121 个 PHP）
│   ├── controllers/           # 前台页面控制器（26 个，每个对应一条前台路由）
│   │   ├── home.php           # 首页（聚合统计/公告/版块/热门）
│   │   ├── forum.php          # 版块帖子列表
│   │   ├── post.php           # 主题帖详情（引用/分页）
│   │   ├── new_post.php       # 发帖
│   │   ├── login.php          # 登录（含记住密码 Web Crypto 加密）
│   │   ├── register.php / logout.php
│   │   ├── profile.php / favorites.php / search.php
│   │   ├── pm.php             # 私信会话
│   │   ├── notifications.php / notification_read.php   # 通知列表 / 标记已读
│   │   ├── checkin.php        # 每日签到
│   │   ├── report.php / appeal.php / banned.php   # 举报 / 申诉 / 封禁提示
│   │   ├── forgot_password.php / reset_password.php / force_change_password.php
│   │   ├── send_email_code.php / send_password_change_code.php   # 邮箱验证码发送
│   │   └── privacy.php / terms.php / disclaimer.php / service.php   # 站点页面（可后台编辑）
│   │
│   ├── admin/                 # 后台（独立入口前缀 /admin）
│   │   ├── controllers/       # 后台页面（26 个）
│   │   │   ├── index.php              # 控制台首页
│   │   │   ├── system_status.php      # 系统状态监控（CPU/内存/温度/网络/磁盘/显卡）
│   │   │   ├── traffic_monitor.php     # 流量监控
│   │   │   ├── site_settings.php / site_pages.php
│   │   │   ├── forums.php / posts.php / replies.php / announcements.php
│   │   │   ├── users.php / user_edit.php / user_groups.php / roles.php
│   │   │   ├── user_ban.php / user_mute.php
│   │   │   ├── reports.php / ban_appeals.php / password_reset_requests.php
│   │   │   ├── sensitive_words.php / sensitive_word_logs.php
│   │   │   ├── medals.php / mail_center.php / backup.php
│   │   │   ├── data_migration.php       # 数据迁移与还原（导出/导入，支持 ZIP 含头像）
│   │   │   ├── update_center.php        # 系统更新中心（检查/手动更新/自动更新设置）
│   │   │   └── captcha_debug.php       # 人机验证调试台
│   │   ├── api/               # 后台 AJAX 接口（21 个，命名 *_ajax.php）
│   │   │   ├── system_status_ajax.php   # 系统状态轮询采集（含诊断 ?diag=1）
│   │   │   ├── traffic_ajax.php / pending_counts_ajax.php
│   │   │   ├── posts_ajax.php / replies_ajax.php / reports_ajax.php
│   │   │   ├── users_ajax.php / users_bulk_ajax.php / users_export_csv.php
│   │   │   ├── user_detail_ajax.php / user_risk_detail_ajax.php
│   │   │   ├── ban_appeals_ajax.php / sensitive_words_ajax.php / sensitive_logs_ajax.php
│   │   │   ├── backup_ajax.php / mail_stats_ajax.php / mail_notify_ajax.php
│   │   │   ├── bounce_ajax.php / diag_auth.php / data_migration_ajax.php
│   │   │   ├── update_ajax.php          # 系统更新中心（检查/下载/校验/更新）
│   │   │   └── (其余见第 11 节)
│   │   └── layout/            # 后台布局
│   │       ├── admin-init.php # 后台鉴权与初始化（被各后台页面 require）
│   │       ├── header.php / footer.php
│   │
│   ├── includes/              # 全局支撑代码
│   │   ├── config.php         # 全局配置常量、多语言加载、安全响应头、业务参数
│   │   ├── functions.php      # 全局函数（鉴权/CSRF/格式化/积分/邮件码/BBCode…）
│   │   ├── db.php             # 核心 Schema、建表/迁移/初始化/默认数据
│   │   ├── database/          # 数据库驱动抽象层
│   │   │   ├── DatabaseFactory.php    # 按 DB_TYPE 创建驱动
│   │   │   ├── AbstractDriver.php     # PDO 封装、跨库查询辅助、重连
│   │   │   ├── SQLiteDriver.php / MySQLDriver.php / PostgreSQLDriver.php
│   │   ├── languages/         # 语言包
│   │   │   ├── zh-CN.php / zh-TW.php / en.php   # 主语言包（核心键）
│   │   │   └── extras/        # 分批扩展语言包（按语言子目录，安装时合并加载）
│   │   │       ├── zh-CN/  zh-TW/  en/          # 各语言专属扩展（admin_ajax/batch_*/mail_center…）
│   │   ├── mailer.php         # 原生 fsockopen SMTP 发送器 + 邮件日志
│   │   ├── bounce_processor.php  # 退信处理
│   │   ├── backup_manager.php    # 数据库备份
│   │   ├── update_center.php    # 系统更新中心（检查/下载/校验/备份/覆盖 共享逻辑）
│   │   ├── compat.php         # 无 mbstring 时的兼容层
│   │   └── header.php / footer.php
│   │
│   ├── components/
│   │   └── sensitive_filter/  # 敏感词过滤引擎
│   │       ├── SensitiveFilter.php   # Trie + Aho-Corasick 匹配内核
│   │       └── helper.php            # 辅助函数
│   │
│   └── captcha/               # 人机验证模块（无第三方依赖，GD 生成资源）
│       ├── core.php           # 验证逻辑、挑战生成与校验
│       ├── api.php            # 验证会话/题目下发接口
│       ├── serve.php          # 资源入口（captcha.js / captcha.css / 背景图）
│       ├── captcha.js         # 前端交互（滑块/点选/交换/字母）
│       └── captcha.css        # 验证组件样式
│
├── public/                    # Web 可访问的静态资源与公共接口
│   ├── api/                   # 公共 JSON 接口（6 个，轮询/上传）
│   │   ├── home_realtime.php / pm_unread.php / pm_poll.php
│   │   ├── post_replies_count.php / check_ban_status.php / upload_image.php
│   ├── css/                   # 样式（8 个）
│   │   ├── tokens.css         # CSS 变量（颜色/圆角/间距）—— 换肤改这里
│   │   ├── style.css / base.css / dark.css   # 主样式 / 基础 / 暗色主题
│   │   ├── header.css / pm.css / profile.css / utilities.css
│   ├── js/                    # 脚本（3 个）
│   │   ├── main.js            # 全局交互（导航/校验/提示/下拉）
│   │   ├── editor.js          # 发帖编辑器（BBCode/上传）
│   │   └── lightbox.js        # 图片灯箱
│   └── images/
│       └── logo.svg           # 站点 Logo
│
├── data/                      # 运行时目录（安装时创建，需可写）
│   ├── site_config.php        # 安装生成的配置（DB_*/SITE_*/SMTP_* 常量）
│   ├── installed.lock         # 安装锁（存在表示已安装）
│   ├── forum.db               # SQLite 数据库（默认）
│   ├── error.log              # 错误日志
│   └── db_version.lock        # Schema 迁移版本锁
│
└── uploads/                   # 上传文件（安装时创建，需可写）
    ├── avatars/               # 用户头像
    └── images/                # 帖子图片
```

> `data/` 与 `uploads/` 在首次安装时创建。**需对 Web 服务器进程可写**。请确认其权限（如 `chmod 755 data uploads`）。`app/`、`public/`、`index.php`、`install.php` 无需写权限。

---

## 4. 环境要求

| 项 | 要求 |
| --- | --- |
| PHP 版本 | ≥ **7.4** |
| 必需扩展 | `PDO`、对应数据库驱动（`pdo_sqlite` 默认；MySQL 需 `pdo_mysql`；PostgreSQL 需 `pdo_pgsql`） |
| 推荐扩展 | `mbstring`（未安装时系统提供兼容层，但建议启用） |
| 目录权限 | `data/`、`uploads/` 可写 |
| Web 服务器 | Apache / Nginx（均无需 rewrite 也可运行，见[路由](#7-路由与访问方式)） |
| 邮件功能 | 可选，需可用的 SMTP 服务（开启后支持邮箱注册验证码与找回密码） |

---

## 5. 安装与部署

### 5.1 快速开始（SQLite，零配置）

1. 将项目文件放到 Web 根目录（或子目录）。
2. 访问 `install.php`（例如 `http://your-domain/install.php`）。
3. 安装向导流程：
   - **语言选择** → **授权协议**（需同意）
   - **第 1 步 数据库**：选择 `SQLite`，使用默认路径 `data/forum.db` 即可。
   - **第 2 步 环境检测**：确认 PHP 版本、PDO、数据库扩展、`data` 目录可写均通过。
   - **第 3 步 站点配置**：填写站点名称（可填副标题），按需启用 SMTP。
   - **第 4 步 完成**：点击「开始安装」，系统自动建表并写入默认数据。
4. 安装完成后，**第一个注册的账号自动成为管理员**（超级管理员）。
5. 登录后访问后台进行版块、角色、权限等详细配置。

### 5.2 使用 MySQL / PostgreSQL

- 在「数据库」步骤选择 `MySQL` 或 `PostgreSQL`，填写主机、端口、库名、用户名、密码。
- 安装程序会自动测试连接；MySQL 会在库不存在时尝试自动创建。
- 如缺少对应 PDO 扩展，向导会给出 `php.ini` 中开启扩展的具体步骤。

### 5.3 Web 服务器示例

**Nginx**（推荐，支持美化 URL）：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
location ~ \.php$ {
    fastcgi_pass   unix:/run/php/php-fpm.sock;
    include        fastcgi_params;
    fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

**Apache**：系统默认兼容 `index.php?route=xxx` 方式；如需美化 URL 可放置 `.htaccess` 重写到 `index.php`。

> 即使不做任何重写配置，系统也能通过 `index.php?route=xxx`、`index.php?s=xxx`、以及 `REQUEST_URI` 兜底三种方式解析路由，详见下一节。

---

## 6. 配置说明

安装生成的配置位于 **`data/site_config.php`**（由 `app/includes/config.php` 自动加载）。常用常量：

| 常量 | 说明 |
| --- | --- |
| `DB_TYPE` | `sqlite` / `mysql` / `pgsql` |
| `DB_FILE` | SQLite 文件路径（仅 SQLite） |
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` | 远程数据库（MySQL/PostgreSQL） |
| `SITE_NAME` / `SITE_SLOGAN` | 站点名称 / 副标题 |
| `SMTP_ENABLED` / `SMTP_HOST` / `SMTP_PORT` / `SMTP_USER` / `SMTP_PASS` / `SMTP_ENCRYPTION` / `SMTP_FROM` / `SMTP_FROM_NAME` | 邮件服务配置 |

`app/includes/config.php` 中还定义了业务可调参数（如分页大小、积分规则、Cookie 策略、时区等），可按需修改：

- `POSTS_PER_PAGE` / `REPLIES_PER_PAGE`：分页大小。
- `CHECKIN_*`：签到积分/金币规则与里程碑奖励。
- `POST_POINTS` / `REPLY_POINTS` / `*_RECEIVED_POINTS`：内容贡献积分。
- `POINTS_DAILY_*_CAP`：每日积分上限（防刷）。
- `COOKIE_SECURE` / `CRED_KEY_COOKIE_DAYS`：Cookie 安全与有效期。
- `error_reporting` / `display_errors`：本地（`127.0.0.1`）显示错误，生产环境仅记录 `data/error.log`。

---

## 7. 路由与访问方式

入口 `index.php` 兼容多种访问形态，无需服务器 rewrite 也能运行：

| 形态 | 示例 |
| --- | --- |
| `route` 参数 | `/index.php?route=forum` |
| `s` 参数（部分 Nginx） | `/index.php?s=forum` |
| 美化 URL（try_files 兜底） | `/forum`、`/post`、`/admin/users` |

**前台路由映射**（节选，对应 `app/controllers/`）：

| 路由 | 页面 | 路由 | 页面 |
| --- | --- | --- | --- |
| `home` | 首页 | `search` | 搜索 |
| `forum` | 版块列表 | `profile` | 个人资料 |
| `post` | 主题详情 | `favorites` | 我的收藏 |
| `new_post` | 发帖 | `checkin` | 签到 |
| `login` / `register` | 登录 / 注册 | `pm` | 私信 |
| `notifications` | 通知 | `report` / `appeal` | 举报 / 申诉 |
| `forgot_password` / `reset_password` | 找回密码 | `banned` | 封禁提示页 |
| `privacy` / `terms` / `disclaimer` / `service` | 站点页面 | `logout` | 退出登录 |

**后台**：以 `/admin` 开头 → `app/admin/controllers/*`；后台 AJAX 以 `/admin/api` 开头 → `app/admin/api/*_ajax.php`。

未安装时访问前台会自动跳转 `install.php`；已安装后访问 `/install` 会自动校验数据库完整性。

---

## 8. 数据库设计

核心表（SQLite 方言，PostgreSQL/MySQL 由安装器翻译）。首次安装时由 `init_db()` 创建核心表，其余由运行期 `ensure_*_table()` 按需补全（保证升级兼容）。

| 表 | 作用 |
| --- | --- |
| `users` | 用户（用户名/邮箱/密码/积分/金币/角色/签到/封禁禁言状态） |
| `forum_categories` | 版块分类 |
| `forums` | 版块（归属分类、图标、主题数、帖子数、最后发帖） |
| `posts` | 主题帖（标题/内容/浏览/回复数/置顶/加精/锁帖） |
| `replies` | 回复（楼层/引用/引用内容，楼中楼） |
| `checkins` | 签到记录 |
| `user_points_log` | 积分明细流水 |
| `favorites` | 收藏 |
| `user_groups` | 用户组（按积分区间自动匹配头衔/等级） |
| `roles` / `user_roles` | 权限角色与用户-角色关联（RBAC） |
| `medals` / `user_medals` | 勋章与用户获得记录 |
| `announcements` | 公告 |
| `site_pages` | 站点页面（隐私/条款/免责/服务条款等，可后台编辑） |
| `site_settings` | 站点设置键值对（后台可编辑） |
| `pm_conversations` / `pm_messages` | 私信会话与消息 |
| `notifications` | 系统通知 |
| `reports` | 内容举报 |
| `ban_appeals` | 封禁申诉 |
| `password_reset_requests` | 密码重置申请 |
| `sensitive_words` / `sensitive_word_whitelist` / `sensitive_word_logs` / `sensitive_word_status_logs` | 敏感词库/白名单/命中日志/状态日志 |
| `mail_logs` / `mail_bounce_config` / `mail_bounce_logs` | 邮件发送日志/退信配置/退信记录 |
| `traffic_stats` / `traffic_visitors` | 流量统计与访客 |

> **迁移机制**：`auto_migrate()` 在运行期按需创建缺失表与索引（`ensure_*_table()` / `ensure_db_indexes()`），并以 `data/db_version.lock` 记录版本，保证老版本升级时不破坏存量数据。

---

## 9. 前台功能

- **浏览**：首页聚合（统计、公告、版块、热门/最新）、版块帖子列表、主题详情（含引用回复、分页）、搜索。
- **发布**：发帖、回复（支持 BBCode、表情、引用、图片上传）、编辑资料、收藏。
- **账户**：注册（可选邮箱验证）、登录（记住密码）、找回/重置密码、强制改密、封禁申诉。
- **互动**：私信（轮询实时未读）、系统通知、每日签到、积分/金币与等级展示、勋章墙。

---

## 10. 后台管理

入口：`/admin`（对应 `app/admin/controllers/`，布局见 `app/admin/layout/`）。主要功能：

| 模块 | 文件（app/admin/controllers） |
| --- | --- |
| 控制台 / 系统状态 | `index.php`、`system_status.php`、`traffic_monitor.php` |
| 站点设置 | `site_settings.php`、`site_pages.php` |
| 内容管理 | `forums.php`、`posts.php`、`replies.php`、`announcements.php` |
| 用户管理 | `users.php`、`user_edit.php`、`user_groups.php`、`roles.php`、`user_ban.php`、`user_mute.php` |
| 审核与合规 | `reports.php`、`ban_appeals.php`、`password_reset_requests.php`、`sensitive_words.php`、`sensitive_word_logs.php` |
| 勋章 | `medals.php` |
| 邮件 | `mail_center.php`（日志/统计/通知/退信配置） |
| 运维 | `backup.php`、`data_migration.php`（数据迁移与还原）、`update_center.php`（系统更新中心） |

后台大量操作通过 `app/admin/api/*_ajax.php` 以 JSON 返回，前端异步调用，并提供待办计数（`pending_counts_ajax.php`）、用户风险详情（`user_risk_detail_ajax.php`）、系统诊断（`diag_auth.php`）等辅助接口。

### 10.1 系统状态监控（system_status）

入口 `/admin/system_status`，由 `app/admin/controllers/system_status.php` 渲染、`app/admin/api/system_status_ajax.php` 采集。前端以 AJAX 轮询方式并行拉取三类数据：

| 数据类型 | 端点参数 | 轮询间隔 | 采集函数 |
| --- | --- | --- | --- |
| 静态信息 | `?static=1` | 仅首次（1 小时缓存） | `ss_get_cpu_info()` / `ss_get_memory_banks()` / `ss_get_disk_hardware()` / `ss_get_gpu_info()` / `ss_get_motherboard_info()` / `ss_get_network_interfaces()` / `ss_get_php_info()` |
| 动态数据 | 默认 | 2 秒 | `ss_sample_cpu_and_memory()`（CPU 负载 + 内存占用） |
| 温度 | `?temps=1` | 3 秒 | `ss_get_temperatures()` |

**多路 CPU 支持**：采集层通过 `ss_wmi_query()` 拉取**全部** `Win32_Processor` 行（双路/多路服务器每颗物理 CPU 一行），聚合 `NumberOfCores` 与 `NumberOfLogicalProcessors`，并标注路数（如 `2 x Intel Xeon E5-2673 v4`）。CPU 实时负载取各路 `LoadPercentage` 的平均值。返回值含 `sockets` 字段，前端展示「X 路处理器」。

**温度采集链路**（Windows，按优先级回退，共 8 层）：

1. `root/OpenHardwareMonitor` WMI（最准确，需安装 OpenHardwareMonitor）
2. `wmic` 命令行 OpenHardwareMonitor
3. `MSAcpi_ThermalZoneTemperature` COM（ACPI 温度区，十分之一开尔文换算）
4. `wmic` 命令行 `MSAcpi_ThermalZoneTemperature`
5. PowerShell CIM `MSAcpi_ThermalZoneTemperature`
6. `Win32_TemperatureProbe`（部分服务器/主板厂商提供）
7. `MSStorageDriver_ATAPISmartData`（解析硬盘 SMART 属性 194/190 获取硬盘温度）
8. PowerShell CIM `Win32_TemperatureProbe` 兜底

> 若全部失败，温度卡片显示「未能获取温度数据（需硬件支持或安装 OpenHardwareMonitor）」。服务器 BMC/IPMI 场景下，安装 OpenHardwareMonitor 可启用最完整的传感器读数。

诊断端点：`/admin/api/system_status_ajax?diag=1` 返回各采集通道的可用性（COM/FFI/PowerShell）、CPU/GPU/内存条原始数据及缓存文件清单，用于排查采集失败原因。

### 10.2 数据迁移与还原（data_migration）

入口 `/admin/data_migration`，由 `app/admin/controllers/data_migration.php` 渲染、`app/admin/api/data_migration_ajax.php` 提供导出/导入接口。用于在不同服务器或重装后迁移站点数据，并支持头像等上传文件随库一并迁移（打包为 ZIP）。

**导出（三种格式，按当前数据库类型提供对应项）**

| 格式 | 文件 | 说明 |
| --- | --- | --- |
| 通用 JSON | `*.json` | 跨数据库兼容的通用格式，携带来源库类型标记（`source_driver`） |
| SQLite SQL | `*.zip` | 当前库为 SQLite 时可用；ZIP 内含 `database_backup.sql` + `uploads/`（头像等）+ `manifest.json` |
| MySQL SQL | `*.zip` | 当前库为 MySQL 时可用；结构同上 |

> 导出文件名默认使用英文（`yunjie_backup_YYYYMMDD_HHMMSS.*`），避免 Windows 资源管理器因中文编码出现乱码；中文原名经 `filename*` 参数供现代浏览器识别显示。

**导入**

- 支持 `.json`、`.sql`、`.zip` 三种文件。
- **跨库保护**：系统自动读取文件来源库类型标记（SQL 文件的 `-- DB-TYPE:` 注释，或 JSON 的 `source_driver`），与**当前库类型不一致时拒绝导入**，防止不同数据库之间互相迁移导致结构不兼容。
- **上传文件还原**：导入 `.zip` 时自动安全解压（禁止路径穿越）→ 将包内 `uploads/` 还原到项目目录 → 执行 SQL；导入后头像、帖子图片等不会丢失。
- **导入前快照**：每次导入前自动创建数据库快照，失败可回滚。
- **进度提示**：导入过程显示阶段性进度条（ZIP：上传 → 解析 → 还原资源 → 快照 → 写入数据库；其他：上传 → 解析 → 快照 → 写入数据库）。

### 10.3 系统更新中心（update_center）

入口 `/admin/update_center`，由 `app/admin/controllers/update_center.php` 渲染、`app/admin/api/update_ajax.php` 提供检查/更新接口，核心逻辑位于 `app/includes/update_center.php`。用于在线检查并应用云界论坛的版本更新，支持**手动**与**自动**两种方式。

**更新设置**

| 设置项 | 说明 |
| --- | --- |
| 更新源地址 | 两种格式：① 目录地址（如 `https://example.com/updates`）→ 自动拼接 `/{通道}/version.json`；② 直链文件（`.json/.txt/.yml/.yaml` 结尾）→ 直接作为版本信息读取（JSON 或纯文本版本号）。留空则无法检查更新。 |
| 更新通道 | `stable`（稳定版）/ `beta`（测试版）/ `dev`（开发版）。 |
| 严格校验 SSL 证书 | 默认关闭。更新源使用自签名证书（如个人服务器）时应保持关闭；仅正规 CA 签发时才开启。 |
| 跳过哈希校验 | 默认强制校验更新包 SHA256/SHA1 哈希以防篡改；仅当更新源无法提供 `package_hash` 且完全信任该源时，才可开启跳过（有被篡改覆盖的风险）。 |
| 启用自动更新 | 开启后按「自动更新间隔」自动检查，并在发现新版本时自动下载、备份并覆盖升级。 |
| 自动更新间隔（小时） | 距上次检查超过该小时数后，再次访问后台即触发自动检查与安装。建议 24（每天一次）。 |

**更新流程（安全优先）**

手动点击「立即更新」或自动更新触发后，均走同一套原子化流程，任意一步失败都会回退、绝不留下半成品：

1. **检查**：拉取 `{base}/{通道}/version.json`，解析最新版本（支持 JSON 与纯文本格式）；`version_compare` 判断是否可用。
2. **下载**：流式下载更新包到 `data/tmp/`，实时回传进度（前端进度条：准备 → 下载 → 校验 → 备份 → 解压覆盖 → 完成）。
3. **校验**：下载完成后严格比对 `package_hash`（SHA256/SHA1），不匹配则立即丢弃并取消更新。
4. **备份**：升级前对现有代码（`app/`、`public/` 及入口文件）整包备份到 `data/backups/update_pre_{时间戳}.zip`，可随时从「数据备份」恢复。
5. **覆盖**：解压更新包到安装根目录，期间**禁止路径穿越**、**禁止覆盖 `data/`**（保留用户数据、配置与数据库）。

> 自动更新同样走「校验 + 备份 + 覆盖」全流程；若更新源未提供 `package_url`，可将更新包命名为 `update.zip` 放在「{通道}/」目录下由系统自动推导。

---

## 11. API 接口

**公共接口**（`public/api/`，返回 JSON）：

| 文件 | 说明 |
| --- | --- |
| `home_realtime.php` | 首页实时数据（缓存聚合） |
| `pm_unread.php` | 私信未读数与最新一条摘要（轮询，2s 服务端缓存） |
| `pm_poll.php` | 私信长轮询 |
| `post_replies_count.php` | 主题回复数实时查询 |
| `check_ban_status.php` | 当前用户封禁/禁言状态 |
| `upload_image.php` | 图片上传 |

**后台接口**（`app/admin/api/`，`*_ajax.php`）：backup、ban_appeals、bounce、data_migration、update、diag_auth、mail_notify、mail_stats、pending_counts、posts、replies、reports、sensitive_logs、sensitive_words、system_status、traffic、user_detail、user_risk_detail、users、users_bulk、users_export_csv。

> 接口普遍使用 `realtime_cache($key, $ttl, $callback)` 做短缓存，避免高频轮询压垮数据库。

---

## 12. 主题与定制

- **样式变量**：`public/css/tokens.css` 定义 CSS 变量（颜色、圆角、间距），改主题色只需调整该文件。
- **明暗主题**：`public/css/dark.css` 为暗色主题，通过 `<body>` 切换（系统/用户偏好）。
- **页面样式**：`style.css`、`base.css`、`header.css`、`pm.css`、`profile.css`、`utilities.css`。
- **脚本**：`public/js/main.js`（全局交互）、`editor.js`（发帖编辑器）、`lightbox.js`（图片灯箱）。
- **图标**：版块图标与 UI 图标使用 SVG / Emoji（`ui_icon()`、`forum_icon()` 等函数生成）。
- **语言包**：`app/includes/languages/*.php`，数组式键值。加载时先 `require` 主包（如 `zh-CN.php`），再从 `extras/{code}/*.php` 分批合并扩展键（如 `admin_ajax.php`、`batch_b01.php`、`mail_center.php`），便于按模块维护与按需加载。新增语言：复制 `zh-CN.php` 为 `xx.php` 翻译主包，在 `extras/xx/` 放扩展包，最后到 `config.php` 的 `get_available_languages()` 登记即可。
- **翻译函数**：`t($key, $default, $vars)` 从全局 `$LANG` 取文本；缺失时回退 `$default`，再否则返回 key 本身；支持 `{var}` 占位符替换（如 `t('welcome', '欢迎，{name}', ['name' => $u])`）。

---

## 13. 安全机制

- **会话安全**：`session.cookie_httponly`、`samesite=Lax`，HTTPS 时自动启用 `secure`；`remember` Cookie 可配置仅 HTTPS。
- **CSRF**：所有写操作经 `validate_csrf()` / `csrf_token()` 校验。
- **密码**：使用 PHP `password_hash`（bcrypt）存储。
- **人机验证（Captcha）**：
  - 独立模块位于 `app/captcha/`，无第三方依赖，无需注册外部服务。
  - 支持「滑块拼图」「点选文字」「推理交换」与「图片字母」**四种挑战模式**，可在后台「站点配置 → 验证方式」一键切换或设为智能混合。
  - 行为打分支持用户通过时无感通过；难度分简单/普通/困难三档（影响字符数、旋转角度、干扰强度等）。
  - 滑块拼图：GD 动态生成 300×150 风景图，随机缺口位置，拖拽后服务端按容差校验。
  - 点选文字：GD 生成背景图并随机散落旋转彩色汉字，用户按提示词顺序点击，顺序完全一致才算通过。
  - 推理交换：将图片切割为网格，随机交换 2 个图块，用户通过拖动交换使图片恢复完整；支持简中/繁中/英文三语提示。
  - 图片字母：GD 绘制扭曲旋转的字母/数字图片（自动剔除易混淆字符），用户输入图片中的字符，不区分大小写；难度越高字符越多、旋转与干扰越强。
  - 资源入口：`/index.php?route=captcha/assets&file=captcha.js|css`。
- **安全响应头**：`X-Content-Type-Options: nosniff`、`X-Frame-Options: SAMEORIGIN`、`Referrer-Policy: strict-origin-when-cross-origin`。
- **输出转义**：`e()` 统一转义输出，防止 XSS。
- **内容审核**：敏感词引擎（Trie + Aho-Corasick）三级处理 + 白名单 + 命中日志；`assess_post_risk()` 评估发帖风险。
- **封禁/禁言**：`banned_until` / `muted_until` 支持自动过期（`auto_expire_user_status()`），过期自动恢复。
- **用户风险**：`compute_user_risk()` 综合行为计算风险等级，辅助后台治理。
- **安装保护**：存在 `installed.lock` 后再次访问安装页会校验数据库完整性，防止重复安装破坏数据；SQLite 路径做了目录遍历防护。

---

## 14. 二次开发指南

**新增前台页面**

1. 在 `app/controllers/` 下新建 `my_page.php`，首行 `require_once APP_ROOT . 'app/includes/functions.php';`，按约定 `include` `header.php` / `footer.php`。
2. 在 `index.php` 的 `$routes` 数组中添加 `'my_page' => 'my_page'` 映射。
3. 访问 `/my_page` 即可。

**新增公共 API**

- 在 `public/api/` 下新建 `xxx.php`，返回 `json_encode([...], JSON_UNESCAPED_UNICODE)`；`index.php` 的 `/api/` 分支会自动加载。

**新增后台页面 / 接口**

- 页面：`app/admin/controllers/xxx.php`（可 `require` `app/admin/layout/admin-init.php` 复用后台布局与鉴权）。
- 接口：`app/admin/api/xxx_ajax.php`，通过 `/admin/api/xxx` 访问。

**数据库操作**

- 读取：`$db = get_db();` 后用 PDO 预处理。
- 新增表：在 `app/includes/db.php` 增加 `ensure_xxx_table(PDO $db)` 函数并在 `auto_migrate()` 中调用，保证幂等与升级兼容（使用 `CREATE TABLE IF NOT EXISTS`）。

**常用全局函数**（`app/includes/functions.php`）

- 鉴权：`is_logged_in()`、`current_user()`、`require_login()`、`require_admin()`、`has_permission()`、`is_admin()`
- 输出/URL：`e()`、`t()`（翻译）、`redirect()`、`site_url()`、`avatar_url()`
- 内容：`bbcode()`、`safe_content()`、`linkify()`、`ui_icon()`、`forum_icon()`
- 积分：`add_user_points()`、`get_user_daily_points()`、`get_user_group()`
- 通知：`send_notification()`、`get_unread_notification_count()`
- 缓存：`realtime_cache()`

---

## 15. 数据备份与维护

- **数据库备份**：后台「备份」(`backup.php`) 调用 `app/includes/backup_manager.php`，**默认执行完整数据库备份**（SQLite 全库文件 / MySQL 全库 `mysqldump`），也支持按指定表导出；SQLite 也可直接复制 `data/forum.db`。
- **数据迁移**：后台「数据迁移」(`data_migration.php`) 支持把业务表导出为 JSON / SQL 格式，再导入到另一个实例，导入前会自动创建快照。导出格式适用场景如下：
  - **通用 JSON**：跨 SQLite / MySQL 环境迁移，支持「合并」与「覆盖」两种导入模式，不带头像等上传文件。
  - **通用 JSON（ZIP 含头像）**：跨环境迁移且需要保留头像、上传文件；ZIP 内为 JSON + `uploads/`，支持合并/覆盖导入。
  - **SQLite / MySQL 数据库（ZIP 含头像）**：同类型数据库整库搬迁；ZIP 内为 SQL + `uploads/`，执行 `DROP TABLE + CREATE TABLE`，仅支持覆盖导入。
- **全表快照开关**：迁移导入前默认只备份本次涉及的业务表，以缩短 `mysqldump` 时间、避免代理超时。如需完整数据库快照，可在 `data/site_config.php` 中定义 `define('MIGRATION_SNAPSHOT_FULL_DB', true);`。
- **迁移与升级**：新版本解压覆盖代码后，运行期 `auto_migrate()` 会自动补全表/索引，无需手工改库。
- **日志**：错误记录在 `data/error.log`；安装期 DDL 执行明细可通过 `get_ddl_install_log()` 查看（安装失败时向导会展示）。
- **退信处理**：`app/includes/bounce_processor.php` 处理邮件退信并更新用户邮箱状态。
- **流量统计**：`track_visit()` 记录访问，后台「流量监控」可查看。

---

## 16. 常见问题 (FAQ)

**Q1. 安装时提示「data 目录不可写」？**
创建并赋予写权限：`mkdir data && chmod 755 data`（Windows 下在目录属性的「安全」中给 Web 进程写权限）。

**Q2. 想换数据库类型（如 SQLite → MySQL）？**
目前安装向导在首次安装时确定数据库类型。切换到远程数据库建议：备份数据 → 修改 `data/site_config.php` 的 `DB_*` 常量 → 在目标库建库 → 重新导入数据。生产环境请先备份。

**Q3. 启用邮件后注册需要邮箱验证码？**
在「站点配置 / 后台邮件中心」启用 SMTP 并正确填写后，注册与找回密码将启用邮箱验证码流程；未启用 SMTP 时走普通注册流程。

**Q4. 如何新增语言？**
在 `app/includes/languages/` 复制 `zh-CN.php` 为 `xx.php` 并翻译，再到 `app/includes/config.php` 的 `get_available_languages()` 增加该语言项即可。

**Q5. 升级后页面报错或表缺失？**
访问一次前台/后台触发 `auto_migrate()` 自动补表；若仍异常，查看 `data/error.log`。必要时可删除 `data/db_version.lock` 强制重新检查迁移（不会删除数据）。

**Q6. 忘记管理员密码？**
可通过「找回密码」（需 SMTP）重置；或直接在数据库 `users` 表用 `password_hash()` 重置对应用户的 `password` 字段。

**Q7. 拼图验证看起来对齐了但提示失败？**
请先强制刷新浏览器（`Ctrl+F5`）以加载最新 `captcha.js`；若容器被 CSS 压缩导致舞台宽度不是 300px，系统会自动按比例换算坐标。也可在后台「站点配置 → 验证方式」临时切到「点选文字」「推理交换」或「图片字母」验证排查。

**Q8. 点选文字验证的汉字显示为方框？**
点选文字依赖 GD 与字体文件渲染。默认使用系统字体，中文显示不佳时请在 `app/captcha/fonts/` 放置中文字体（如 `SourceHanSansSC-Regular.otf`），系统将自动优先使用。

**Q9. 如何启用「推理交换」验证？**
在「站点配置 → 人机验证」中选择「推理交换验证（交换图块还原图片）」，可配置触发模式（始终/可疑/高风险）与显示方式（内嵌/弹窗/触发）。该模式支持简中/繁中/英文提示。

**Q10. 人机验证的「触发模式」在哪里设置？**
在「站点配置 → 人机验证」的「显示方式」下拉中选择「触发模式（鼠标移入输入框时弹出验证）」，用户聚焦输入框时自动弹出验证窗口，体验更友好。

---

> 文档基于项目源码（`index.php`、`install.php`、`app/includes/*`、`public/*`）整理，版本 `1.3.7-beta`。
> 如与代码实现不符，请以代码与安装向导提示为准。

---

## 其他语言版本

- [English (README.en.md)](README.en.md)
- [繁體中文 (README.zh-TW.md)](README.zh-TW.md)
