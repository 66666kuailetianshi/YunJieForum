# 云界论坛 (Cloud Forum)

> **Current Status: Beta** (v1.3.4-beta) | Lightweight community forum · PHP + SQLite · Out-of-the-box

**[简体中文](README.md) · [繁體中文](README.zh-TW.md)**

`Cloud Forum` is a lightweight community forum (BBS) system written entirely in PHP. It uses a SQLite file database by default, so it can run without a standalone database server — suitable for personal blog communities, interest groups, intranet knowledge bases, and similar scenarios. The system includes a built-in user system, forums/posts/replies, private messages, notifications, daily check-ins with points, medals and roles, content moderation (sensitive words), email and traffic statistics, and provides a visual installation wizard and admin panel.

- **Current Version:** `1.3.4-beta`
- **Language:** PHP 7.4+
- **Default Database:** SQLite (also supports MySQL / PostgreSQL)
- **Frontend:** Native HTML + CSS + a small amount of native JS, no frontend build step
- **Multi-language:** Simplified Chinese / Traditional Chinese / English

---

## Table of Contents

- [1. Core Features](#1-core-features)
- [2. Technical Architecture](#2-technical-architecture)
- [3. Directory Structure](#3-directory-structure)
- [4. Environment Requirements](#4-environment-requirements)
- [5. Installation & Deployment](#5-installation--deployment)
- [6. Configuration Guide](#6-configuration-guide)
- [7. Routing & Access Modes](#7-routing--access-modes)
- [8. Database Design](#8-database-design)
- [9. Frontend Features](#9-frontend-features)
- [10. Admin Panel](#10-admin-panel)
- [11. API Endpoints](#11-api-endpoints)
- [12. Themes & Customization](#12-themes--customization)
- [13. Security Mechanisms](#13-security-mechanisms)
- [14. Development Guide](#14-development-guide)
- [15. Data Backup & Maintenance](#15-data-backup--maintenance)
- [16. FAQ](#16-faq)

---

## 1. Core Features

| Module | Description |
| --- | --- |
| User System | Registration, login, remember me, profile, change password, email verification code (requires SMTP), password recovery, forced password change, ban appeal |
| Content System | Multi-level forums (category → forum), topics, replies (reply-within-reply / quote), sticky, featured, locked, favorites, search |
| Points / Levels | Earn points and coins for posting/reply/receiving replies/being favorited, with daily reward caps to prevent farming; **user groups** and titles are automatically assigned by points; **medal** system |
| Check-in | Daily check-in earns points + coins, consecutive check-ins increase rewards, 7/30-day milestones give extra rewards |
| Social Interaction | In-site private messages (PM, real-time reminders via frontend polling), system notifications, @ mentions |
| Permissions / Roles | Permission groups based on `roles` (`has_permission`), supporting super admin, moderators, etc.; separate from **user groups** that auto-promote by points |
| Content Moderation | Sensitive-word filtering engine (Trie + Aho-Corasick), supporting exact / whole-word / regex matching, whitelist, three-level handling (replace / block / manual review), hit logs; user reports, ban appeals, mute |
| Email | Native SMTP sender implemented with `fsockopen` (no third-party dependencies), supports SSL/TLS, mail logs, bounce handling, mail statistics and notifications |
| Ops & Monitoring | Traffic statistics (visit records), system status, database backup, automatic schema migration, installation/error logs |
| Multi-language | Built-in `Simplified Chinese / Traditional Chinese / English`, auto-detected by URL, Cookie, config, and browser language |
| Themes | Light/dark dual themes based on CSS variables (light / dark), customizable colors and skins |
| CAPTCHA | Supports "slider jigsaw", "click text", and "reasoning swap" challenge modes with one-click switching or smart mixing; display modes include inline embedding and popup; behavioral scoring allows seamless verification for legitimate users |

---

## 2. Technical Architecture

The system uses a **single entry point + front controller** pattern, with no MVC framework. It is built entirely on native PHP, with a clear structure that is easy to deploy.

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

**Database abstraction layer** (`app/includes/database/`):

- `DatabaseFactory`: creates the driver based on config (`sqlite` / `mysql` / `pgsql`).
- `AbstractDriver`: wraps PDO, provides cross-database compatible query helpers, and implements mechanisms such as reconnection.
- `SQLiteDriver` / `MySQLDriver` / `PostgreSQLDriver`: database-specific implementations (connection strings, PRAGMA/SET NAMES, type and pagination differences).
- Globally, `get_db()` returns the PDO instance and `get_db_driver()` returns the driver instance. The DDL in the installation wizard goes through **dialect translation** to adapt to different databases.

---

## 3. Directory Structure

```
云界论坛/
├── index.php                  # 前台入口 / 路由分发
├── install.php                # 安装向导（4 步 + 语言/授权前置）
├── app/
│   ├── controllers/           # 前台页面控制器（26 个）
│   │   ├── home.php           # 首页
│   │   ├── forum.php          # 版块帖子列表
│   │   ├── post.php           # 主题帖详情
│   │   ├── new_post.php       # 发帖
│   │   ├── login.php / register.php / logout.php
│   │   ├── profile.php / favorites.php / search.php
│   │   ├── pm.php / notifications.php
│   │   ├── checkin.php        # 签到
│   │   ├── report.php / appeal.php / banned.php
│   │   ├── forgot_password.php / reset_password.php / force_change_password.php
│   │   ├── send_email_code.php / send_password_change_code.php
│   │   ├── privacy.php / terms.php / disclaimer.php / service.php   # 站点页面
│   │   └── ...
│   ├── admin/
│   │   ├── controllers/       # 后台页面（站点设置、用户、版块、帖子、举报、邮件、备份、统计…）
│   │   ├── api/               # 后台 AJAX 接口（*_ajax.php）
│   │   └── layout/           # 后台布局（header / footer / admin-init）
│   ├── includes/
│   │   ├── config.php         # 全局配置常量、多语言、安全响应头
│   │   ├── functions.php      # 全局函数（鉴权、CSRF、格式化、积分、邮件码、BBCode…）
│   │   ├── db.php             # 建表/迁移/初始化/默认数据（核心 Schema）
│   │   ├── database/          # 数据库驱动抽象（见架构）
│   │   ├── languages/         # 语言包 zh-CN.php / zh-TW.php / en.php
│   │   ├── mailer.php         # SMTP 发送器 + 邮件日志
│   │   ├── bounce_processor.php  # 退信处理
│   │   ├── backup_manager.php    # 数据库备份
│   │   ├── compat.php         # 无 mbstring 时的兼容层
│   │   └── header.php / footer.php
│   └── components/
│       └── sensitive_filter/  # 敏感词过滤引擎（SensitiveFilter.php + helper.php）
├── public/
│   ├── api/                   # 公共 JSON 接口（轮询/上传等）
│   ├── css/                   # 样式（style / base / dark / tokens / header / pm / profile / utilities）
│   ├── js/                    # main.js / editor.js / lightbox.js
│   └── images/                # logo.svg 等
├── data/                      # 运行时目录（安装时创建，需可写）
│   ├── site_config.php        # 安装生成的配置（DB / SITE / SMTP 常量）
│   ├── installed.lock         # 安装锁（存在表示已安装）
│   ├── forum.db               # SQLite 数据库（默认）
│   ├── error.log
│   └── db_version.lock        # Schema 迁移版本锁
└── uploads/                   # 上传文件（头像 avatars/、图片 images/）
```

> `data/` and `uploads/` are created on first installation. **They must be writable by the web server process.** Please verify their permissions (e.g. `chmod 755 data uploads`).

---

## 4. Environment Requirements

| Item | Requirement |
| --- | --- |
| PHP Version | ≥ **7.4** |
| Required Extensions | `PDO`, the corresponding database driver (`pdo_sqlite` by default; MySQL needs `pdo_mysql`; PostgreSQL needs `pdo_pgsql`) |
| Recommended Extensions | `mbstring` (the system provides a compatibility layer if not installed, but enabling it is recommended) |
| Directory Permissions | `data/`, `uploads/` writable |
| Web Server | Apache / Nginx (both work without rewrite rules, see [Routing](#7-routing--access-modes)) |
| Email Features | Optional, requires a working SMTP service (enables email verification code for registration and password recovery) |

---

## 5. Installation & Deployment

### 5.1 Quick Start (SQLite, zero configuration)

1. Put the project files in the web root (or a subdirectory).
2. Visit `install.php` (e.g. `http://your-domain/install.php`).
3. Installation wizard flow:
   - **Language selection** → **License agreement** (must be accepted)
   - **Step 1 Database**: choose `SQLite` and use the default path `data/forum.db`.
   - **Step 2 Environment check**: confirm that the PHP version, PDO, database extensions, and `data` directory writability all pass.
   - **Step 3 Site configuration**: fill in the site name (and optionally a slogan), enable SMTP as needed.
   - **Step 4 Done**: click "Start Installation" and the system automatically creates the tables and writes the default data.
4. After installation, **the first registered account automatically becomes the administrator** (super admin).
5. Log in and visit the admin panel to configure forums, roles, permissions, and more.

### 5.2 Using MySQL / PostgreSQL

- In the "Database" step, choose `MySQL` or `PostgreSQL` and fill in the host, port, database name, username, and password.
- The installer automatically tests the connection; for MySQL it will try to auto-create the database if it does not exist.
- If the corresponding PDO extension is missing, the wizard shows the specific steps to enable it in `php.ini`.

### 5.3 Web Server Examples

**Nginx** (recommended, supports pretty URLs):

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

**Apache**: the system is compatible with the `index.php?route=xxx` mode by default; for pretty URLs you can place a `.htaccess` rewrite to `index.php`.

> Even without any rewrite configuration, the system can resolve routes through `index.php?route=xxx`, `index.php?s=xxx`, and a `REQUEST_URI` fallback — see the next section for details.

---

## 6. Configuration Guide

The configuration generated during installation lives at **`data/site_config.php`** (auto-loaded by `app/includes/config.php`). Commonly used constants:

| Constant | Description |
| --- | --- |
| `DB_TYPE` | `sqlite` / `mysql` / `pgsql` |
| `DB_FILE` | SQLite file path (SQLite only) |
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` | Remote database (MySQL/PostgreSQL) |
| `SITE_NAME` / `SITE_SLOGAN` | Site name / slogan |
| `SMTP_ENABLED` / `SMTP_HOST` / `SMTP_PORT` / `SMTP_USER` / `SMTP_PASS` / `SMTP_ENCRYPTION` / `SMTP_FROM` / `SMTP_FROM_NAME` | Email service configuration |

`app/includes/config.php` also defines business tunable parameters (e.g. pagination size, points rules, cookie policy, timezone, etc.) that can be modified as needed:

- `POSTS_PER_PAGE` / `REPLIES_PER_PAGE`: pagination size.
- `CHECKIN_*`: check-in points/coins rules and milestone rewards.
- `POST_POINTS` / `REPLY_POINTS` / `*_RECEIVED_POINTS`: content contribution points.
- `POINTS_DAILY_*_CAP`: daily points cap (anti-farming).
- `COOKIE_SECURE` / `CRED_KEY_COOKIE_DAYS`: cookie security and lifetime.
- `error_reporting` / `display_errors`: errors shown locally (`127.0.0.1`), only logged to `data/error.log` in production.

---

## 7. Routing & Access Modes

The entry point `index.php` is compatible with several access modes and works without any server rewrite:

| Mode | Example |
| --- | --- |
| `route` parameter | `/index.php?route=forum` |
| `s` parameter (some Nginx) | `/index.php?s=forum` |
| Pretty URL (try_files fallback) | `/forum`, `/post`, `/admin/users` |

**Frontend route mapping** (excerpt, corresponding to `app/controllers/`):

| Route | Page | Route | Page |
| --- | --- | --- | --- |
| `home` | Home | `search` | Search |
| `forum` | Forum list | `profile` | Profile |
| `post` | Topic detail | `favorites` | My favorites |
| `new_post` | New post | `checkin` | Check-in |
| `login` / `register` | Login / Register | `pm` | Private messages |
| `notifications` | Notifications | `report` / `appeal` | Report / Appeal |
| `forgot_password` / `reset_password` | Password recovery | `banned` | Ban notice page |
| `privacy` / `terms` / `disclaimer` / `service` | Site pages | `logout` | Logout |

**Admin panel**: routes starting with `/admin` → `app/admin/controllers/*`; admin AJAX endpoints starting with `/admin/api` → `app/admin/api/*_ajax.php`.

If the site is not installed yet, visiting the frontend automatically redirects to `install.php`; after installation, visiting `/install` automatically verifies database integrity.

---

## 8. Database Design

Core tables (SQLite dialect; PostgreSQL/MySQL are translated by the installer). Core tables are created by `init_db()` during first installation; the rest are created on demand at runtime by `ensure_*_table()` (for upgrade compatibility).

| Table | Purpose |
| --- | --- |
| `users` | Users (username/email/password/points/coins/role/check-in/ban-mute status) |
| `forum_categories` | Forum categories |
| `forums` | Forums (parent category, icon, topic count, post count, last post) |
| `posts` | Topics (title/content/views/reply count/sticky/featured/locked) |
| `replies` | Replies (floor/quote/quoted content, reply-within-reply) |
| `checkins` | Check-in records |
| `user_points_log` | Points transaction ledger |
| `favorites` | Favorites |
| `user_groups` | User groups (titles/levels auto-matched by points ranges) |
| `roles` / `user_roles` | Permission roles and user-role relations (RBAC) |
| `medals` / `user_medals` | Medals and user medal records |
| `announcements` | Announcements |
| `site_pages` | Site pages (privacy/terms/disclaimer/service, editable in admin) |
| `site_settings` | Site settings key-value pairs (editable in admin) |
| `pm_conversations` / `pm_messages` | PM conversations and messages |
| `notifications` | System notifications |
| `reports` | Content reports |
| `ban_appeals` | Ban appeals |
| `password_reset_requests` | Password reset requests |
| `sensitive_words` / `sensitive_word_whitelist` / `sensitive_word_logs` / `sensitive_word_status_logs` | Sensitive-word dictionary/whitelist/hit logs/status logs |
| `mail_logs` / `mail_bounce_config` / `mail_bounce_logs` | Mail sending logs/bounce config/bounce records |
| `traffic_stats` / `traffic_visitors` | Traffic statistics and visitors |

> **Migration mechanism**: `auto_migrate()` creates missing tables and indexes on demand at runtime (`ensure_*_table()` / `ensure_db_indexes()`), and records the version in `data/db_version.lock`, so upgrading from older versions never breaks existing data.

---

## 9. Frontend Features

- **Browsing**: home aggregation (stats, announcements, forums, hot/latest), forum topic lists, topic detail (with quoted replies, pagination), search.
- **Posting**: new posts, replies (supports BBCode, emoji, quotes, image uploads), profile editing, favorites.
- **Account**: registration (optional email verification), login (remember me), forgot/reset password, forced password change, ban appeal.
- **Interaction**: private messages (real-time unread via polling), system notifications, daily check-in, points/coins and level display, medal wall.

---

## 10. Admin Panel

Entry: `/admin` (corresponds to `app/admin/controllers/`, layout in `app/admin/layout/`). Main features:

| Module | Files (app/admin/controllers) |
| --- | --- |
| Dashboard / System status | `index.php`, `system_status.php`, `traffic_monitor.php` |
| Site settings | `site_settings.php`, `site_pages.php` |
| Content management | `forums.php`, `posts.php`, `replies.php`, `announcements.php` |
| User management | `users.php`, `user_edit.php`, `user_groups.php`, `roles.php`, `user_ban.php`, `user_mute.php` |
| Review & compliance | `reports.php`, `ban_appeals.php`, `password_reset_requests.php`, `sensitive_words.php`, `sensitive_word_logs.php` |
| Medals | `medals.php` |
| Email | `mail_center.php` (logs/statistics/notifications/bounce config) |
| Ops | `backup.php` |

Many admin operations are handled through `app/admin/api/*_ajax.php`, returning JSON for asynchronous frontend calls, with auxiliary endpoints for pending counts (`pending_counts_ajax.php`), user risk details (`user_risk_detail_ajax.php`), and system diagnostics (`diag_auth.php`).

---

## 11. API Endpoints

**Public endpoints** (`public/api/`, return JSON):

| File | Description |
| --- | --- |
| `home_realtime.php` | Home realtime data (cached aggregation) |
| `pm_unread.php` | PM unread count and latest message summary (polling, 2s server-side cache) |
| `pm_poll.php` | PM long polling |
| `post_replies_count.php` | Realtime reply count for a topic |
| `check_ban_status.php` | Current user's ban/mute status |
| `upload_image.php` | Image upload |

**Admin endpoints** (`app/admin/api/`, `*_ajax.php`): backup, ban_appeals, bounce, diag_auth, mail_notify, mail_stats, pending_counts, posts, replies, reports, sensitive_logs, sensitive_words, system_status, traffic, user_detail, user_risk_detail, users, users_bulk, users_export_csv.

> Endpoints generally use `realtime_cache($key, $ttl, $callback)` for short caching, avoiding high-frequency polling from overwhelming the database.

---

## 12. Themes & Customization

- **Style variables**: `public/css/tokens.css` defines CSS variables (colors, radius, spacing); change the theme color by editing just this file.
- **Light/dark themes**: `public/css/dark.css` is the dark theme, toggled via `<body>` (system/user preference).
- **Page styles**: `style.css`, `base.css`, `header.css`, `pm.css`, `profile.css`, `utilities.css`.
- **Scripts**: `public/js/main.js` (global interactions), `editor.js` (posting editor), `lightbox.js` (image lightbox).
- **Icons**: forum icons and UI icons use SVG / Emoji (generated by functions such as `ui_icon()`, `forum_icon()`).
- **Language packs**: `app/includes/languages/*.php`, array-style key-value pairs; to add a language, simply add a new pack file and register it in `get_available_languages()` in `config.php`.

---

## 13. Security Mechanisms

- **Session security**: `session.cookie_httponly`, `samesite=Lax`, with `secure` automatically enabled over HTTPS; the `remember` Cookie can be configured HTTPS-only.
- **CSRF**: all write operations are validated via `validate_csrf()` / `csrf_token()`.
- **Passwords**: stored using PHP `password_hash` (bcrypt).
- **Security response headers**: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`.
- **Output escaping**: `e()` uniformly escapes output to prevent XSS.
- **Content moderation**: sensitive-word engine (Trie + Aho-Corasick) with three-level handling + whitelist + hit logs; `assess_post_risk()` evaluates posting risk.
- **Ban/mute**: `banned_until` / `muted_until` support automatic expiry (`auto_expire_user_status()`), with automatic recovery after expiry.
- **User risk**: `compute_user_risk()` computes a risk level from combined behavior, assisting admin governance.
- **Install protection**: once `installed.lock` exists, visiting the installer again verifies database integrity to prevent re-installation from destroying data; the SQLite path is protected against directory traversal.

---

## 14. Development Guide

**Adding a frontend page**

1. Create `my_page.php` under `app/controllers/`, with `require_once APP_ROOT . 'app/includes/functions.php';` as the first line, and `include` `header.php` / `footer.php` per convention.
2. Add the `'my_page' => 'my_page'` mapping to the `$routes` array in `index.php`.
3. Visit `/my_page`.

**Adding a public API endpoint**

- Create `xxx.php` under `public/api/` and return `json_encode([...], JSON_UNESCAPED_UNICODE)`; the `/api/` branch of `index.php` auto-loads it.

**Adding an admin page / endpoint**

- Page: `app/admin/controllers/xxx.php` (can `require` `app/admin/layout/admin-init.php` to reuse the admin layout and auth).
- Endpoint: `app/admin/api/xxx_ajax.php`, accessed via `/admin/api/xxx`.

**Database operations**

- Reading: `$db = get_db();` then use PDO prepared statements.
- Adding tables: add an `ensure_xxx_table(PDO $db)` function in `app/includes/db.php` and call it in `auto_migrate()`, ensuring idempotency and upgrade compatibility (use `CREATE TABLE IF NOT EXISTS`).

**Common global functions** (`app/includes/functions.php`)

- Auth: `is_logged_in()`, `current_user()`, `require_login()`, `require_admin()`, `has_permission()`, `is_admin()`
- Output/URL: `e()`, `t()` (translation), `redirect()`, `site_url()`, `avatar_url()`
- Content: `bbcode()`, `safe_content()`, `linkify()`, `ui_icon()`, `forum_icon()`
- Points: `add_user_points()`, `get_user_daily_points()`, `get_user_group()`
- Notifications: `send_notification()`, `get_unread_notification_count()`
- Cache: `realtime_cache()`

---

## 15. Data Backup & Maintenance

- **Database backup**: the "Backup" page in admin (`backup.php`) calls `app/includes/backup_manager.php`; for SQLite you can also simply copy `data/forum.db`.
- **Migration & upgrade**: after overwriting the code with a new version, runtime `auto_migrate()` automatically creates missing tables/indexes — no manual database changes needed.
- **Logs**: errors are recorded in `data/error.log`; installation-time DDL execution details can be inspected via `get_ddl_install_log()` (shown by the wizard on installation failure).
- **Bounce handling**: `app/includes/bounce_processor.php` processes bounced emails and updates user email status.
- **Traffic statistics**: `track_visit()` records visits, viewable under "Traffic monitor" in the admin panel.

---

## 16. FAQ

**Q1. The installer says "data directory is not writable"?**
Create it and grant write permission: `mkdir data && chmod 755 data` (on Windows, give the web process write permission under the directory's "Security" tab).

**Q2. Want to switch database types (e.g. SQLite → MySQL)?**
Currently the installer determines the database type at first installation. To switch to a remote database, it is recommended to: back up data → modify the `DB_*` constants in `data/site_config.php` → create the database on the target server → re-import the data. Always back up first in production.

**Q3. After enabling email, is a verification code required for registration?**
Once SMTP is enabled and configured correctly in "Site config / admin Mail center", registration and password recovery will use the email verification code flow; when SMTP is not enabled, the normal registration flow applies.

**Q4. How do I add a new language?**
Copy `zh-CN.php` to `xx.php` in `app/includes/languages/` and translate it, then add the language entry to `get_available_languages()` in `app/includes/config.php`.

**Q5. Pages error out or tables are missing after an upgrade?**
Visit the frontend/admin once to trigger `auto_migrate()` to auto-create tables; if issues persist, check `data/error.log`. If necessary, delete `data/db_version.lock` to force a re-check of migrations (this does not delete data).

**Q6. Forgot the admin password?**
Reset it via "Forgot password" (requires SMTP); or directly reset the corresponding user's `password` field in the `users` table using `password_hash()`.

**Q7. Jigsaw verification seems aligned but fails?**
First force-refresh the browser (`Ctrl+F5`) to load the latest `captcha.js`. If the container is squeezed by CSS so the stage width is not 300px, the system will automatically scale coordinates proportionally. You can also temporarily switch to "Click text" or "Reasoning swap" in "Site settings → Verification method" to troubleshoot.

**Q8. Chinese characters display as boxes in click-text verification?**
Click-text rendering depends on GD and a font file. It falls back to the system font by default; if Chinese characters display poorly, place a Chinese font (e.g. `SourceHanSansSC-Regular.otf`) in `app/captcha/fonts/` and the system will prefer it automatically.

**Q9. How to enable Reasoning Swap verification?**
In "Site Settings → CAPTCHA Settings", select "Reasoning Swap Verification (swap tiles to restore image)", and configure trigger mode (always/suspicious/high-risk) and display mode (inline/popup/trigger). This mode supports Simplified Chinese/Traditional Chinese/English hints.

### 常见问题 (FAQ)

**Q7. Jigsaw verification seems aligned but fails?**
First force-refresh the browser (`Ctrl+F5`) to load the latest `captcha.js`. If the container is squeezed by CSS so the stage width is not 300px, the system will automatically scale coordinates proportionally. You can also temporarily switch to "Click text" or "Reasoning swap" in "Site settings → Verification method" to troubleshoot.

**Q8. Chinese characters display as boxes in click-text verification?**
Click-text rendering depends on GD and a font file. It falls back to the system font by default; if Chinese characters display poorly, place a Chinese font (e.g. `SourceHanSansSC-Regular.otf`) in `app/captcha/fonts/` and the system will prefer it automatically.

**Q10. Where is Trigger Mode for CAPTCHA set?**
In "Site Settings → CAPTCHA Settings", find the "Display Mode" dropdown and select "Trigger Mode (show verification when mouse enters)". The verification window will automatically pop up when users move their mouse to input fields, providing a more user-friendly experience.

---

> Documentation compiled from the project source (`index.php`, `install.php`, `app/includes/*`, `public/*`), version `1.3.4-beta`.
> If it differs from the actual implementation, please follow the code and the installation wizard prompts.


