# 云界论坛 (Cloud Forum)

> **Current Status: Stable** (v1.5.2) | Lightweight community forum · PHP + SQLite · Out-of-the-box

**[简体中文](README.md) · [繁體中文](README.zh-TW.md)**

`Cloud Forum` is a lightweight community forum (BBS) system written entirely in PHP. It uses a SQLite file database by default, so it can run without a standalone database server — suitable for personal blog communities, interest groups, intranet knowledge bases, and similar scenarios. The system includes a built-in user system, forums/posts/replies, private messages, notifications, daily check-ins with points, medals and roles, content moderation (sensitive words), email and traffic statistics, and provides a visual installation wizard and admin panel.

- **Current Version:** `1.5.2`
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
| Content System | Multi-level forums (category → forum), topics, replies (reply-within-reply / quote), sticky, featured, locked, favorites, search, sharing (links automatically follow the current access domain) |
| Points / Levels | Earn points and coins for posting/reply/receiving replies/being favorited, with daily reward caps to prevent farming; **user groups** and titles are automatically assigned by points; **medal** system |
| Check-in | Daily check-in earns points + coins, consecutive check-ins increase rewards, 7/30-day milestones give extra rewards |
| Social Interaction | In-site private messages (PM, real-time reminders via frontend polling), system notifications, @ mentions |
| Permissions / Roles | Permission groups based on `roles` (`has_permission`), supporting super admin, moderators, etc.; separate from **user groups** that auto-promote by points |
| Content Moderation | Sensitive-word filtering engine (Trie + Aho-Corasick), supporting exact / whole-word / regex matching, whitelist, three-level handling (replace / block / manual review), hit logs; user reports, ban appeals, mute |
| Email | Native SMTP sender implemented with `fsockopen` (no third-party dependencies), supports SSL/TLS, mail logs, bounce handling, mail statistics and notifications |
| Ops & Monitoring | Traffic statistics (visit records: exact PV counting + session-level UV dedup + crawler filtering + region attribution, see [Section 10.4](#104-traffic-monitor-traffic_monitor)), IP database management (offline ip2region format, optional install: download from GitHub/domestic cloud drive, upload/query/delete, see [Section 10.5](#105-ip-database-management-ip_database)), system status, database backup, automatic schema migration, installation/error logs |
| System Update | "System Update Center" supports manual/automatic update checking and applying: download → verify SHA256 hash → auto-backup → overwrite upgrade; historical update backups can be listed/downloaded/shared/deleted, see [Section 10.3](#103-system-update-center-update_center) |
| Multi-language | Built-in `Simplified Chinese / Traditional Chinese / English`, auto-detected by URL, Cookie, config, and browser language |
| Themes | Light/dark dual themes based on CSS variables (light / dark), customizable colors and skins |
| CAPTCHA | Supports "slider jigsaw", "click text", and "reasoning swap" challenge modes with one-click switching or smart mixing; display modes include inline embedding and popup; behavioral scoring allows seamless verification for legitimate users |
| Ticket System | Front-end "Feedback" lets users submit issue tickets; the admin ticket center handles both user feedback and internal admin tickets (source filter: user / admin), with a status workflow (pending → in progress → resolved / closed) and follow-up replies; new submissions notify admins automatically |
| Email Disclosure | Admins cannot see user emails by default (privacy protection); they can submit a disclosure request (reason required) in User Management, visible only after super-admin approval; pending/unconsumed requests lock the apply entry to prevent duplicates |

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

> The tree below is **measured from the actual project layout** (`app/` contains 129 PHP files + assets). Lines with `#` are files that actually exist in this project.

```
Cloud Forum/
├── index.php                  # Front controller / router (single entry; parses route/s/REQUEST_URI)
├── install.php                # Installation wizard (DB → env check → site config → done; language & license pre-steps)
├── LICENSE                    # Open-source license text
├── README.md / README.en.md / README.zh-TW.md   # Tri-lingual project docs
│
├── app/                       # Application core (129 PHP files)
│   ├── controllers/           # Front-end page controllers (27 files, each maps to one route)
│   │   ├── home.php           # Home (stats/announcements/forums/trending)
│   │   ├── forum.php          # Forum thread list
│   │   ├── post.php           # Topic detail (quote / pagination)
│   │   ├── new_post.php       # Create post
│   │   ├── login.php          # Login (with Web Crypto encrypted "remember me")
│   │   ├── register.php / logout.php
│   │   ├── profile.php / favorites.php / search.php
│   │   ├── pm.php             # Private message conversations
│   │   ├── notifications.php / notification_read.php   # Notifications / mark-as-read
│   │   ├── checkin.php        # Daily check-in
│   │   ├── report.php / appeal.php / banned.php   # Report / appeal / ban notice
│   │   ├── forgot_password.php / reset_password.php / force_change_password.php
│   │   ├── send_email_code.php / send_password_change_code.php   # Email code senders
│   │   ├── privacy.php / terms.php / disclaimer.php / service.php   # Site pages (editable in admin)
│   │   └── ticket.php           # Feedback (submit / list / detail / reply)
│   │
│   ├── admin/                 # Admin panel (entry prefix /admin)
│   │   ├── controllers/       # Admin pages (29 files)
│   │   │   ├── index.php              # Dashboard home
│   │   │   ├── system_status.php      # System status monitor (CPU/memory/temp/network/disk/GPU)
│   │   │   ├── traffic_monitor.php     # Traffic monitor
│   │   │   ├── site_settings.php / site_pages.php
│   │   │   ├── forums.php / posts.php / replies.php / announcements.php
│   │   │   ├── users.php / user_edit.php / user_groups.php / roles.php / user_create.php
│   │   │   ├── user_ban.php / user_mute.php
│   │   │   ├── reports.php / ban_appeals.php / password_reset_requests.php
│   │   │   ├── sensitive_words.php / sensitive_word_logs.php
│   │   │   ├── tickets.php / email_disclosure.php   # Ticket system / email disclosure review
│   │   │   ├── medals.php / mail_center.php / backup.php
│   │   │   ├── data_migration.php       # Data migration & restore (export/import, ZIP with avatars)
│   │   │   ├── update_center.php        # System Update Center (check/manual update/auto-update settings)
│   │   │   └── captcha_debug.php       # CAPTCHA debug console
│   │   ├── api/               # Admin AJAX endpoints (20 files, named *_ajax.php)
│   │   │   ├── system_status_ajax.php   # System status poller (with ?diag=1 diagnostics)
│   │   │   ├── traffic_ajax.php / pending_counts_ajax.php
│   │   │   ├── posts_ajax.php / replies_ajax.php / reports_ajax.php
│   │   │   ├── users_ajax.php / users_bulk_ajax.php / users_export_csv.php
│   │   │   ├── user_detail_ajax.php / user_risk_detail_ajax.php
│   │   │   ├── ban_appeals_ajax.php / sensitive_words_ajax.php / sensitive_logs_ajax.php
│   │   │   ├── backup_ajax.php / mail_stats_ajax.php / mail_notify_ajax.php
│   │   │   ├── bounce_ajax.php / data_migration_ajax.php
│   │   │   ├── update_ajax.php          # System Update Center (check/download/verify/update)
│   │   │   └── (others — see Section 11)
│   │   └── layout/            # Admin layout
│   │       ├── admin-init.php # Admin auth & init (required by each admin page)
│   │       ├── admin-helpers.php # Shared admin helper functions (post flags / ticket link routing)
│   │       ├── header.php / footer.php
│   │
│   ├── includes/              # Global support code
│   │   ├── config.php         # Global config constants, i18n loader, security headers, business params
│   │   ├── functions.php      # Global functions (auth/CSRF/format/points/mail-code/BBCode…)
│   │   ├── db.php             # Core schema, table migration/init/default data
│   │   ├── database/          # Database driver abstraction layer
│   │   │   ├── DatabaseFactory.php    # Creates driver by DB_TYPE
│   │   │   ├── AbstractDriver.php     # PDO wrapper, cross-DB helpers, reconnection
│   │   │   ├── SQLiteDriver.php / MySQLDriver.php / PostgreSQLDriver.php
│   │   ├── languages/         # Language packs
│   │   │   ├── zh-CN.php / zh-TW.php / en.php   # Main packs (core keys)
│   │   │   └── extras/        # Batched extension packs (per-language subdirs, merged at load)
│   │   │       ├── zh-CN/  zh-TW/  en/          # Per-language extras (admin_ajax/batch_*/mail_center…)
│   │   ├── mailer.php         # Native fsockopen SMTP sender + mail log
│   │   ├── bounce_processor.php  # Bounce handling
│   │   ├── backup_manager.php    # DB backup
│   │   ├── update_center.php    # System Update Center (check/download/verify/backup/overwrite shared logic)
│   │   ├── compat.php         # Fallback layer when mbstring is absent
│   │   └── header.php / footer.php
│   │
│   ├── components/
│   │   └── sensitive_filter/  # Sensitive-word filtering engine
│   │       ├── SensitiveFilter.php   # Trie + Aho-Corasick matching core
│   │       └── helper.php            # Helpers
│   │
│   └── captcha/               # CAPTCHA module (no 3rd-party deps; GD-generated assets)
│       ├── core.php           # Validation logic, challenge generation & verification
│       ├── api.php            # Challenge session / token dispatch
│       ├── serve.php          # Asset entry (captcha.js / captcha.css / background images)
│       ├── captcha.js         # Front-end interaction (slider / click / swap)
│       └── captcha.css        # CAPTCHA component styles
│
├── public/                    # Web-accessible static assets & public APIs
│   ├── api/                   # Public JSON endpoints (6 files; polling / upload)
│   │   ├── home_realtime.php / pm_unread.php / pm_poll.php
│   │   ├── post_replies_count.php / check_ban_status.php / upload_image.php
│   ├── css/                   # Stylesheets (10 files)
│   │   ├── tokens.css         # CSS variables (colors/radius/spacing) — theme skinning here
│   │   ├── style.css / base.css / dark.css / components.css   # Main / base / dark / components
│   │   ├── header.css / pm.css / profile.css / utilities.css
│   │   └── admin.css        # Admin styles
│   ├── js/                    # Scripts (3 files)
│   │   ├── main.js            # Global interactions (nav/validation/alerts/dropdown)
│   │   ├── editor.js          # Post editor (BBCode/upload)
│   │   └── lightbox.js        # Image lightbox
│   └── images/
│       └── logo.svg           # Site logo
│
├── data/                      # Runtime dir (created on install; must be writable)
│   ├── site_config.php        # Install-generated config (DB_*/SITE_*/SMTP_* constants)
│   ├── installed.lock         # Install lock (presence = installed)
│   ├── forum.db               # SQLite database (default)
│   ├── error.log              # Error log
│   └── db_version.lock        # Schema migration version lock
│
├── uploads/                   # Uploaded files (created on install; must be writable)
│   ├── avatars/               # User avatars
│   └── images/                # Post images
│
└── tools/                     # Dev helper scripts (admin CSS segment builds, etc.)
```

> `data/` and `uploads/` are created on first installation. **They must be writable by the web server process.** Verify permissions (e.g. `chmod 755 data uploads`). `app/`, `public/`, `index.php`, `install.php` need no write access.

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

# ===== Security rules (required) =====
# The data/ directory holds the database, config credentials, backups and sessions —
# deny all web access.
location ^~ /data/ {
    deny all;
}
# Block direct downloads of sensitive file extensions (matches only these suffixes;
# does not affect images and other normal files under public/ or uploads/).
location ~* \.(db|sqlite|sqlite3|sql|zip|gz|log|cache|lock)$ {
    deny all;
}
```

> ⚠️ **Security note**: the bundled `.htaccess` files only apply to **Apache**. **Nginx / BT Panel (宝塔)** users are not served by `.htaccess` and must manually add the corresponding `deny` rules to the server (site) configuration as shown above; otherwise the database, configuration and backup files under `data/` can be downloaded anonymously.

**Apache**: the system is compatible with the `index.php?route=xxx` mode by default; for pretty URLs you can place a `.htaccess` rewrite to `index.php`. The project ships with built-in security `.htaccess` rules for the root and `data/` directories (automatically effective under Apache).

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
| `ticket` | Feedback (submit / list / detail / reply) | | |

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
| `email_disclosure_requests` | Email disclosure requests (applicant/target user/reason/status/viewed flag) |
| `site_pages` | Site pages (privacy/terms/disclaimer/service, editable in admin) |
| `site_settings` | Site settings key-value pairs (editable in admin) |
| `pm_conversations` / `pm_messages` | PM conversations and messages |
| `notifications` | System notifications |
| `reports` | Content reports |
| `ban_appeals` | Ban appeals |
| `password_reset_requests` | Password reset requests |
| `sensitive_words` / `sensitive_word_whitelist` / `sensitive_word_logs` / `sensitive_word_status_logs` | Sensitive-word dictionary/whitelist/hit logs/status logs |
| `tickets` / `ticket_replies` | Tickets and follow-up replies (source: user feedback / admin tickets) |
| `mail_logs` / `mail_bounce_config` / `mail_bounce_logs` | Mail sending logs/bounce config/bounce records |
| `traffic_stats` / `traffic_visitors` | Traffic statistics and visitors |

> **Migration mechanism**: `auto_migrate()` creates missing tables and indexes on demand at runtime (`ensure_*_table()` / `ensure_db_indexes()`), and records the version in `data/db_version.lock`, so upgrading from older versions never breaks existing data.

---

## 9. Frontend Features

- **Browsing**: home aggregation (stats, announcements, forums, hot/latest), forum topic lists, topic detail (with quoted replies, pagination), search.
- **Posting**: new posts, replies (supports BBCode, emoji, quotes, image uploads), profile editing, favorites, post sharing (one-click copy of the full link on the current access domain).
- **Account**: registration (optional email verification), login (remember me), forgot/reset password, forced password change, ban appeal.
- **Interaction**: private messages (real-time unread via polling), system notifications, daily check-in, points/coins and level display, medal wall.
- **Feedback**: feedback tickets (submit issues, track progress, follow-up replies); new tickets notify admins automatically.

---

## 10. Admin Panel

Entry: `/admin` (corresponds to `app/admin/controllers/`, layout in `app/admin/layout/`). Main features:

| Module | Files (app/admin/controllers) |
| --- | --- |
| Dashboard / System status | `index.php`, `system_status.php`, `traffic_monitor.php` |
| Site settings | `site_settings.php`, `site_pages.php` |
| Content management | `forums.php`, `posts.php`, `replies.php`, `announcements.php` |
| User management | `users.php`, `user_edit.php`, `user_groups.php`, `roles.php`, `user_ban.php`, `user_mute.php`, `user_create.php` |
| Review & compliance | `reports.php`, `ban_appeals.php`, `password_reset_requests.php`, `sensitive_words.php`, `sensitive_word_logs.php`, `email_disclosure.php` |
| Ticket system | `tickets.php` (unified handling of user feedback & admin tickets; source filter & status workflow) |
| Medals | `medals.php` |
| Email | `mail_center.php` (logs/statistics/notifications/bounce config) |
| Ops | `backup.php`, `data_migration.php` (data migration & restore), `update_center.php` (System Update Center), `ip_database.php` (IP database management) |

Many admin operations are handled through `app/admin/api/*_ajax.php`, returning JSON for asynchronous frontend calls, with auxiliary endpoints for pending counts (`pending_counts_ajax.php`) and user risk details (`user_risk_detail_ajax.php`).

### 10.1 System Status Monitor (system_status)

Entry `/admin/system_status`, rendered by `app/admin/controllers/system_status.php` and collected by `app/admin/api/system_status_ajax.php`. The front-end pulls three data types in parallel via AJAX polling:

| Data type | Endpoint param | Poll interval | Collector function |
| --- | --- | --- | --- |
| Static info | `?static=1` | first load only (1h cache) | `ss_get_cpu_info()` / `ss_get_memory_banks()` / `ss_get_disk_hardware()` / `ss_get_gpu_info()` / `ss_get_motherboard_info()` / `ss_get_network_interfaces()` / `ss_get_php_info()` |
| Dynamic data | default | 2 seconds | `ss_sample_cpu_and_memory()` (CPU load + memory usage) |
| Temperature | `?temps=1` | 3 seconds | `ss_get_temperatures()` |

**Multi-socket CPU**: the collector uses `ss_wmi_query()` to fetch **all** `Win32_Processor` rows (one row per physical CPU on dual/multi-socket servers), aggregates `NumberOfCores` and `NumberOfLogicalProcessors`, and labels the socket count (e.g. `2 x Intel Xeon E5-2673 v4`). Real-time CPU load is the average of each socket's `LoadPercentage`. The response includes a `sockets` field; the front-end shows "X sockets".

**Temperature collection chain** (Windows, priority-ordered fallback, 8 layers total):

1. `root/OpenHardwareMonitor` WMI (most accurate; requires OpenHardwareMonitor installed)
2. `wmic` command-line OpenHardwareMonitor
3. `MSAcpi_ThermalZoneTemperature` COM (ACPI thermal zone, tenths-of-Kelvin conversion)
4. `wmic` command-line `MSAcpi_ThermalZoneTemperature`
5. PowerShell CIM `MSAcpi_ThermalZoneTemperature`
6. `Win32_TemperatureProbe` (provided by some server/mainboard vendors)
7. `MSStorageDriver_ATAPISmartData` (parse HDD/SSD SMART attribute 194/190 for drive temperature)
8. PowerShell CIM `Win32_TemperatureProbe` fallback

> If all fail, the temperature card shows "Unable to read temperature data (requires hardware support or OpenHardwareMonitor installed)". On server BMC/IPMI setups, installing OpenHardwareMonitor enables the most complete sensor readings.

Diagnostic endpoint: `/admin/api/system_status_ajax?diag=1` returns the availability of each collection channel (COM/FFI/PowerShell), raw CPU/GPU/memory data, and the cache-file manifest, for troubleshooting collection failures.

### 10.2 Data Migration & Restore (data_migration)

Entry `/admin/data_migration`, rendered by `app/admin/controllers/data_migration.php`, with export/import served by `app/admin/api/data_migration_ajax.php`. Used to migrate site data to another server or after a reinstall, and supports bundling uploaded files (e.g. avatars) together with the database as a ZIP.

**Export (three formats; the available options depend on the current database type)**

| Format | File | Notes |
| --- | --- | --- |
| Generic JSON | `*.json` | Database-agnostic format carrying a source-driver marker (`source_driver`) |
| SQLite SQL | `*.zip` | Available when the current DB is SQLite; ZIP contains `database_backup.sql` + `uploads/` (avatars, etc.) + `manifest.json` |
| MySQL SQL | `*.zip` | Available when the current DB is MySQL; same structure as above |

> Exported filenames use ASCII by default (`yunjie_backup_YYYYMMDD_HHMMSS.*`) to avoid mojibake in Windows Explorer; the original Chinese name is provided via the `filename*` parameter for modern browsers.

**Import**

- Accepts `.json`, `.sql`, and `.zip` files.
- **Cross-database protection**: the system auto-detects the source database type from the file (`-- DB-TYPE:` comment in SQL, or `source_driver` in JSON) and **rejects the import if it differs from the current database type**, preventing incompatible migrations between different databases.
- **Uploaded-file restore**: when importing a `.zip`, the archive is safely extracted (path-traversal protected) → its `uploads/` is restored into the project directory → then the SQL is executed; avatars and post images are not lost after import.
- **Pre-import snapshot**: a database snapshot is created automatically before every import, so a failed import can be rolled back.
- **Progress indicator**: a staged progress bar is shown during import (ZIP: upload → parse → restore assets → snapshot → write to DB; others: upload → parse → snapshot → write to DB).

### 10.3 System Update Center (update_center)

Entry `/admin/update_center`, rendered by `app/admin/controllers/update_center.php` and served by `app/admin/api/update_ajax.php`, with core logic in `app/includes/update_center.php`. Used to check for and apply Yunjie Forum version updates online, supporting three methods: **remote manual update**, **local package upload**, and **automatic update**.

**Update settings**

| Setting | Description |
| --- | --- |
| Update source URL | Two formats: ① Directory URL (e.g. `https://example.com/updates`) → auto-appends `/{channel}/version.json`; ② Direct file link (ending in `.json/.txt/.yml/.yaml`) → used directly as version info (JSON or plain-text version). Left empty, update checks are disabled. |
| Update channel | `stable` / `beta` / `dev`. |
| Strict SSL verification | Off by default. Keep off when the update source uses a self-signed certificate (e.g. a personal server); enable only when the source is signed by a trusted CA. |
| Skip hash verification | SHA256/SHA1 hash of the update package is verified by force by default to prevent tampering; only enable skipping when the source cannot provide `package_hash` AND you fully trust it (risk of a tampered package overwriting the site). |
| Enable auto-update | When on, the system checks automatically at the "auto-update interval" and, upon finding a new version, downloads, backs up, and overwrites to upgrade. |
| Auto-update interval (hours) | After this many hours since the last check, visiting the admin panel again triggers an automatic check and install. Recommended 24 (once a day). |

**Update flow (security-first)**

Both a manual "Update now" click and an automatic trigger go through the same atomic flow; any step failure rolls back and never leaves a half-applied state:

1. **Check**: fetch `{base}/{channel}/version.json`, parse the latest version (JSON or plain text supported); `version_compare` decides availability.
2. **Download**: stream the package to `data/tmp/` with live progress (front-end progress bar: preparing → downloading → verifying → backing up → extracting → done).
3. **Verify**: after download, strictly compare `package_hash` (SHA256/SHA1); on mismatch the package is discarded and the update is cancelled.
4. **Backup**: before upgrading, back up the current code (`app/`, `public/`, and entry files) as a ZIP to `data/backups/update_pre_{timestamp}.zip`, restorable anytime from "Backup".
5. **Overwrite**: extract the package to the install root; **path traversal is forbidden** and **overwriting `data/` is forbidden** (preserves user data, config, and the database).

> Auto-update also goes through the full "verify + backup + overwrite" flow; if the source provides no `package_url`, name the package `update.zip` and place it under the `{channel}/` directory so the system derives it automatically.

**Manual package upload**

No remote update source is needed: choose a local `.zip` update package under the "Update Status" card. The system parses the in-package version (reading `APP_VERSION` in `app/includes/config.php`) and shows the file count, size, and its relation to the current version (upgrade / same-version reinstall / downgrade) before installing. Installation follows the same atomic "backup + overwrite" flow:

- The upload is saved to `data/tmp/upload_update_input.zip`; only `.zip` is accepted (up to 256MB) and the file is removed automatically after installation;
- The package must expose a readable `APP_VERSION` before installing (a safeguard against uploading the wrong archive);
- Identical to remote updates: automatic code backup, path-traversal protection, and `data/` is never overwritten;
- When the in-package version is lower than the current one (downgrade) or unreadable, the front end warns explicitly before you confirm.

**Historical Update Backup Management**

Below the update settings, a "Update Backup History" card centrally manages the `update_pre_*.zip` code backups created automatically before each update (server-side pagination, 10 per page):

- **Download**: one-time download token derived via HMAC (based on the CSRF token, constant-time comparison); strict filename whitelist prevents path traversal.
- **Share**: generates a public link that downloads without login (48-char random token, 7-day expiry by default; the share record is cleaned up automatically when the backup is deleted); the link domain always follows the domain currently visited in the browser (auto-derived, unaffected by the `SITE_URL` setting — works out of the box on multi-domain / reverse-proxy deployments).
- **Delete**: POST + CSRF validation; the corresponding share record is removed as well.

### 10.4 Traffic Monitor (traffic_monitor)

Entry at `/admin/traffic_monitor`, rendered by `app/admin/controllers/traffic_monitor.php` with data served by `app/admin/api/traffic_ajax.php`; the front end polls every 5 seconds, and total-level stats use a 30-second file cache (`data/cache/traffic_total_stats.json`) to avoid full-table scans on every poll.

**Measurement methodology & accuracy design** (`track_visit()`, `app/includes/functions.php`):

| Metric | Methodology |
| --- | --- |
| PV | **Exactly incremented** on every page view, no longer affected by throttling (rapid same-session browsing is no longer undercounted) |
| UV | Deduplicated per "session × hour" (at most 1 per session per hour); never missed across hour boundaries |
| Crawler filter | Requests whose UA matches known crawlers/CLI clients (e.g. googlebot, curl, python-requests) or with no UA are ignored |
| Online count | Based on visitor-row `last_visit` (last 5 minutes), lagging ≤60s due to the session throttle |
| Hot pages / device breakdown | Based on visitor-row `views`, an approximate per-session-window value (slightly undercounted during rapid same-session browsing) |
| Total UV | Row count of `traffic_visitors`, i.e. "cumulative daily-visitor records" (the same IP is counted once per day) |

> Tracking runs only on front-end pages (`app/includes/header.php`); public polling endpoints (`pm_poll.php`, `home_realtime.php`, etc.) and admin pages never trigger it, so polling traffic cannot pollute the data. IPs are stored as SHA-256 hashes, never in plain text.

### 10.5 IP Database Management (ip_database)

Entry at `/admin/ip_database`, rendered by `app/admin/controllers/ip_database.php` with interfaces served by `app/admin/api/ip_db_ajax.php`; the query logic lives in `app/includes/ip2region.php`. It reads the official compiled xdb binaries of the open-source project [ip2region](https://github.com/lionsoul2014/ip2region) (dual-licensed Apache-2.0 OR MIT; full license text in [LICENSE.md](LICENSE.md)) directly — `app/data/ip2region_v4.xdb` / `app/data/ip2region_v6.xdb` — querying them at runtime in the official xdb 3.0 format, fully offline, with no network access, no SQLite driver and no preprocessing.

**Data source (optional install)**: the IP database is optional and is **not shipped with the code** by default. Install it from the admin panel:

1. **One-click download from GitHub**: in "Download IP Database", click "Download & Install from GitHub"; the server pulls the two official v4/v6 xdb files directly from the official repository, validates and installs them (the server must be able to reach GitHub).
2. **One-click download from the domestic cloud drive**: click "Download & Install from Domestic Cloud Drive"; the server pulls the two v4/v6 xdb files from the domestic cloud drive (pan.szczk.top) and validates them. If the server cannot reach that drive, use the fallback links below to download manually and import via "Upload Update".
3. You can also upload official xdb files from any source via "Upload Update" (validated, atomically replaced, takes effect immediately). A missing IP database only affects region display; everything else keeps working.

**Features**

- **Status display**: whether the two xdb files exist, paths, data scale (IPv4/IPv6 segment counts), total size, update time (xdb build time), sample checks (built-in queries against several public IPs), and visitor-region coverage ratio.
- **Download & install**: both the GitHub and domestic cloud drive sources are pulled server-side in one click, validated and installed (atomic replace, immediate effect); the domestic cloud drive also offers manual download fallback links.
- **Online query**: enter any IPv4/IPv6 address to get its geolocation instantly — `province·city` at home, `Chinese country (English name) · province · city` abroad (English and foreign cities are shown too) — handy for verifying the data.
- **Upload update**: upload the official `ip2region_v4.xdb` / `ip2region_v6.xdb` (up to 200MB each); v4/v6 is auto-detected from the `ipVersion` in the file header, then validated and atomically replaced, taking effect immediately.
- **Delete**: a danger-zone button removes the xdb files; new visits no longer record regions afterwards, while historical region data stays displayed.

**Direct-read & lookup internals** (xdb 3.0 binary format, ported from the official PHP Searcher logic):

1. **File layout**: a 256-byte header (version / build time / segment-index range pointers / IP version) → a 512KiB vector index (256×256×8B, locating the segment-index range by the first two bytes of the IP) → a shared text pool (delimiter-free UTF-8) → the segment-index region (14B per row for v4, 38B per row for v6: start/end closed range + dataLen/dataPtr; v4 segment IPs are stored little-endian).
2. **Lookup algorithm**: coarse vector-index location → binary search over the segment index → read the hit text. v4 has 553,958 segments / v6 734,952; one query costs ~20 file reads.
3. **Bilingual**: the geolocation text has 5 fields `country|province|city|ISP|country_code`; a built-in 247-entry English→Chinese country map shows foreign regions as `Chinese country (English name)` while keeping the foreign province/city; China keeps `province·city`.
4. **Memory footprint**: only the 512KiB vector index is kept in memory; the data region is read on demand via fseek/fread, suitable for shared hosting. The searcher is reused by file mtime plus an in-memory cache of the latest 512 results, satisfying the high-frequency calls from `track_visit()`.

**Integration with traffic stats**: `track_visit()` calls `ip_region_query()` during tracking and writes only the **region text** (province/city/country) into the `traffic_visitors.region` column (plain-text IPs are still stored only as SHA-256 hashes). The Traffic Monitor page thereby shows a "region" column in Recent Visitors and a "Region Distribution" card (aggregated by province at home, by country abroad, top 10).

> If no IP database is installed, the system degrades gracefully: `region` stays empty and the region card shows "Unknown", while the rest of the traffic statistics are unaffected.

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
| `share_backup.php` | Shared download of historical update backups (public; requires a 48-char random token, 7-day expiry by default) |

**Admin endpoints** (`app/admin/api/`, `*_ajax.php`): backup, ban_appeals, bounce, data_migration, ip_db, mail_notify, mail_stats, pending_counts, posts, replies, reports, sensitive_logs, sensitive_words, system_status, traffic, update, user_detail, user_risk_detail, users, users_bulk, users_export_csv.

> Endpoints generally use `realtime_cache($key, $ttl, $callback)` for short caching, avoiding high-frequency polling from overwhelming the database.

---

## 12. Themes & Customization

- **Style variables**: `public/css/tokens.css` defines CSS variables (colors, radius, spacing); change the theme color by editing just this file.
- **Light/dark themes**: `public/css/dark.css` is the dark theme, toggled via `<body>` (system/user preference).
- **Page styles**: `style.css`, `base.css`, `header.css`, `pm.css`, `profile.css`, `utilities.css`.
- **Scripts**: `public/js/main.js` (global interactions), `editor.js` (posting editor), `lightbox.js` (image lightbox).
- **Icons**: forum icons and UI icons use SVG / Emoji (generated by functions such as `ui_icon()`, `forum_icon()`).
- **Language packs**: `app/includes/languages/*.php`, array-style key-value pairs. At load time the main pack (e.g. `zh-CN.php`) is `require`d first, then extension keys from `extras/{code}/*.php` (e.g. `admin_ajax.php`, `batch_b01.php`, `mail_center.php`) are merged per module for easier maintenance and on-demand loading. To add a language: copy `zh-CN.php` into `xx.php` as the main pack, drop extension packs under `extras/xx/`, then register it in `get_available_languages()` in `config.php`.
- **Translation function**: `t($key, $default, $vars)` fetches text from the global `$LANG`; when missing it falls back to `$default`, then to the key itself; supports `{var}` placeholder substitution (e.g. `t('welcome', 'Welcome, {name}', ['name' => $u])`).

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

- **Database backup**: the "Backup" page in admin (`backup.php`) calls `app/includes/backup_manager.php`. It **performs full-database backups by default** (complete SQLite database file / full MySQL `mysqldump`) and also supports table-level exports. For SQLite you can also simply copy `data/forum.db`.
- **Data migration**: the "Data Migration" page in admin (`data_migration.php`) lets you export business tables to JSON / SQL and import them into another instance. A pre-import snapshot is created automatically. Export format use cases:
  - **Universal JSON**: cross-environment migration between SQLite and MySQL; supports both "merge" and "overwrite" import modes; does not include avatars/uploads.
  - **Universal JSON (ZIP with Avatars)**: cross-environment migration while keeping avatars and uploads; ZIP contains JSON + `uploads/`; supports merge/overwrite import.
  - **SQLite / MySQL Database (ZIP+Avatars)**: whole-database relocation on the same database type; ZIP contains SQL + `uploads/` and runs `DROP TABLE + CREATE TABLE`; overwrite import only.
- **Merge import mechanism**: primary-key conflicts automatically get a new ID with downstream foreign keys remapped in sync (users/posts/PM relations stay intact); business unique keys (username, category name, forum name, conversation pair, etc.) reuse existing records instead of creating duplicates; cross-version schema differences are protected at column level (extra columns stripped, missing columns fall back to defaults), so a single bad row no longer rolls back the whole import; overwrite mode re-inserts the currently logged-in admin after truncating `users`, preventing lockout from the admin panel.
- **Full-database snapshot switch**: by default, the pre-import snapshot only covers the business tables being migrated, reducing `mysqldump` time and avoiding proxy timeouts. To snapshot the entire database instead, define `define('MIGRATION_SNAPSHOT_FULL_DB', true);` in `data/site_config.php`.
- **Migration & upgrade**: after overwriting the code with a new version, runtime `auto_migrate()` automatically creates missing tables/indexes — no manual database changes needed.
- **Logs**: errors are recorded in `data/error.log`; installation-time DDL execution details can be inspected via `get_ddl_install_log()` (shown by the wizard on installation failure).
- **Bounce handling**: `app/includes/bounce_processor.php` processes bounced emails and updates user email status.
- **Traffic statistics**: `track_visit()` records visits (exact PV counting, session-level UV dedup, crawler filtering), viewable under "Traffic monitor" in the admin panel — see [Section 10.4](#104-traffic-monitor-traffic_monitor).

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

**Q10. Where is Trigger Mode for CAPTCHA set?**
In "Site Settings → CAPTCHA Settings", find the "Display Mode" dropdown and select "Trigger Mode (show verification when the mouse enters an input field)". The verification window will automatically pop up when users move their mouse to input fields, providing a more user-friendly experience.

---

> Documentation compiled from the project source (`index.php`, `install.php`, `app/includes/*`, `public/*`), version `1.5.2`.
> If it differs from the actual implementation, please follow the code and the installation wizard prompts.


