# 雲界論壇 (Cloud Forum)

> **當前狀態：Beta 測試版**（`v1.3.4-beta`）｜ 輕量級社區論壇系統 · PHP + SQLite · 開箱即用

**[简体中文](README.md) · [English](README.en.md)**

`雲界論壇` 是一套純 PHP 編寫的輕量級社區論壇（BBS）系統，默認使用 SQLite 文件資料庫，無需獨立部署資料庫伺服器即可運行，適合個人博客社區、興趣小組、內網知識庫等場景。系統內置用戶體系、版塊/帖子/回復、私信、通知、籤到積分、勳章角色、內容審核（敏感詞）、郵件與流量統計等完整功能，並提供可視化的安裝嚮導與後臺管理。

- **當前版本：** `1.3.4-beta`
- **開發語言：** PHP 7.4+
- **默認資料庫：** SQLite（同時支持 MySQL / PostgreSQL）
- **前端：** 原生 HTML + CSS + 少量原生 JS，無前端構建步驟
- **多語言：** 簡體中文 / 繁體中文 / English

---

## 目錄

- [1. 核心特性](#1-核心特性)
- [2. 技術架構](#2-技術架構)
- [3. 目錄結構](#3-目錄結構)
- [4. 環境要求](#4-環境要求)
- [5. 安裝與部署](#5-安裝與部署)
- [6. 配置說明](#6-配置說明)
- [7. 路由與訪問方式](#7-路由與訪問方式)
- [8. 資料庫設計](#8-資料庫設計)
- [9. 前臺功能](#9-前臺功能)
- [10. 後臺管理](#10-後臺管理)
- [11. API 接口](#11-api-接口)
- [12. 主題與定製](#12-主題與定製)
- [13. 安全機制](#13-安全機制)
- [14. 二次開發指南](#14-二次開發指南)
- [15. 數據備份與維護](#15-數據備份與維護)
- [16. 常見問題 (FAQ)](#16-常見問題-faq)

---

## 1. 核心特性

| 模塊 | 說明 |
| --- | --- |
| 用戶系統 | 註冊、登錄、記住密碼、個人資料、修改密碼、郵箱驗證碼（需 SMTP）、找回密碼、強制改密、封禁申訴 |
| 內容體系 | 多級版塊（分類 → 版塊）、主題帖、回復（樓中樓/引用）、置頂、加精、鎖帖、收藏、搜索 |
| 積分 / 等級 | 發帖/回復/收回復/被收藏獲得積分與金幣，每日獎勵上限防刷；按積分自動劃分**用戶組**與頭銜；**勳章**系統 |
| 籤到 | 每日籤到得積分+金幣，連續籤到遞增獎勵，7/30 天裡程碑額外獎勵 |
| 社交互動 | 站內私信（PM，前端輪詢實時提醒）、系統通知、@ 提醒 |
| 權限 / 角色 | 基於 `roles` 的權限組（`has_permission`），支持超級管理員、版主等；與按積分自動晉升的**用戶組**分離 |
| 內容審核 | 敏感詞過濾引擎（Trie + Aho-Corasick），支持精確/整詞/正則三種匹配、白名單、三級處理（替換 / 攔截 / 人工審核）、命中日誌；用戶舉報、封禁申訴、禁言 |
| 郵件 | 原生 `fsockopen` 實現的 SMTP 發送器（無第三方依賴），支持 SSL/TLS，郵件日誌、退信處理（bounce）、郵件統計與通知 |
| 運維監控 | 流量統計（訪問記錄）、系統狀態、資料庫備份、自動 Schema 遷移、安裝/錯誤日誌 |
| 多語言 | 內置 `簡體中文 / 繁體中文 / English`，按 URL、Cookie、配置、瀏覽器語言自動識別 |
| 主題 | 基於 CSS 變量的明暗雙主題（light / dark），可改色與換膚 |
| 人機驗證 | 內置「滑塊拼圖」與「點選文字」雙模式人機驗證，支持 GD 生成背景圖、行為打分與後臺一鍵切換，無需第三方服務 |

---

## 2. 技術架構

系統採用**單一入口 + 前端控制器**模式，無 MVC 框架，全部基於原生 PHP，結構清晰、易於部署。

```
瀏覽器請求
   │
   ▼
index.php  ── 路由解析（route / s / REQUEST_URI）→ 分發
   │
   ├── /admin/*           → app/admin/controllers/*.php  +  app/admin/api/*.php
   ├── /install           → install.php（安裝嚮導）
   ├── /api/*             → public/api/*.php（公共 JSON 接口）
   └── 前臺路由            → app/controllers/*.php（頁面）
                              │
                              ├── app/includes/functions.php（全局函數/工具）
                              ├── app/includes/db.php（Schema/遷移/初始化）
                              ├── app/includes/database/*（資料庫驅動抽象）
                              └── app/includes/mailer.php / bounce_processor.php

配置：data/site_config.php（安裝生成，含 DB_* / SITE_* / SMTP_* 常量）
數據：data/forum.db（SQLite）/ 遠程資料庫
運行時：data/error.log、data/db_version.lock、uploads/*
```

**資料庫抽象層**（`app/includes/database/`）：

- `DatabaseFactory`：根據配置創建驅動（`sqlite` / `mysql` / `pgsql`）。
- `AbstractDriver`：封裝 PDO，提供跨庫兼容的查詢輔助方法，並實現重連等機制。
- `SQLiteDriver` / `MySQLDriver` / `PostgreSQLDriver`：各庫特定實現（連接串、PRAGMA/SET NAMES、類型與分頁差異）。
- 全局通過 `get_db()` 獲取 PDO、`get_db_driver()` 獲取驅動實例。安裝嚮導中的 DDL 會經過**方言翻譯**以適配不同資料庫。

---

## 3. 目錄結構

```
雲界論壇/
├── index.php                  # 前臺入口 / 路由分發
├── install.php                # 安裝嚮導（4 步 + 語言/授權前置）
├── app/
│   ├── controllers/           # 前臺頁面控制器（26 個）
│   │   ├── home.php           # 首頁
│   │   ├── forum.php          # 版塊帖子列表
│   │   ├── post.php           # 主題帖詳情
│   │   ├── new_post.php       # 發帖
│   │   ├── login.php / register.php / logout.php
│   │   ├── profile.php / favorites.php / search.php
│   │   ├── pm.php / notifications.php
│   │   ├── checkin.php        # 籤到
│   │   ├── report.php / appeal.php / banned.php
│   │   ├── forgot_password.php / reset_password.php / force_change_password.php
│   │   ├── send_email_code.php / send_password_change_code.php
│   │   ├── privacy.php / terms.php / disclaimer.php / service.php   # 站點頁面
│   │   └── ...
│   ├── admin/
│   │   ├── controllers/       # 後臺頁面（站點設置、用戶、版塊、帖子、舉報、郵件、備份、統計…）
│   │   ├── api/               # 後臺 AJAX 接口（*_ajax.php）
│   │   └── layout/           # 後臺布局（header / footer / admin-init）
│   ├── includes/
│   │   ├── config.php         # 全局配置常量、多語言、安全響應頭
│   │   ├── functions.php      # 全局函數（鑑權、CSRF、格式化、積分、郵件碼、BBCode…）
│   │   ├── db.php             # 建表/遷移/初始化/默認數據（核心 Schema）
│   │   ├── database/          # 資料庫驅動抽象（見架構）
│   │   ├── languages/         # 語言包 zh-CN.php / zh-TW.php / en.php
│   │   ├── mailer.php         # SMTP 發送器 + 郵件日誌
│   │   ├── bounce_processor.php  # 退信處理
│   │   ├── backup_manager.php    # 資料庫備份
│   │   ├── compat.php         # 無 mbstring 時的兼容層
│   │   └── header.php / footer.php
│   └── components/
│       └── sensitive_filter/  # 敏感詞過濾引擎（SensitiveFilter.php + helper.php）
├── public/
│   ├── api/                   # 公共 JSON 接口（輪詢/上傳等）
│   ├── css/                   # 樣式（style / base / dark / tokens / header / pm / profile / utilities）
│   ├── js/                    # main.js / editor.js / lightbox.js
│   └── images/                # logo.svg 等
├── data/                      # 運行時目錄（安裝時創建，需可寫）
│   ├── site_config.php        # 安裝生成的配置（DB / SITE / SMTP 常量）
│   ├── installed.lock         # 安裝鎖（存在表示已安裝）
│   ├── forum.db               # SQLite 資料庫（默認）
│   ├── error.log
│   └── db_version.lock        # Schema 遷移版本鎖
└── uploads/                   # 上傳文件（頭像 avatars/、圖片 images/）
```

> `data/` 與 `uploads/` 在首次安裝時創建。**需對 Web 伺服器進程可寫**。請確認其權限（如 `chmod 755 data uploads`）。

---

## 4. 環境要求

| 項 | 要求 |
| --- | --- |
| PHP 版本 | ≥ **7.4** |
| 必需擴展 | `PDO`、對應資料庫驅動（`pdo_sqlite` 默認；MySQL 需 `pdo_mysql`；PostgreSQL 需 `pdo_pgsql`） |
| 推薦擴展 | `mbstring`（未安裝時系統提供兼容層，但建議啟用） |
| 目錄權限 | `data/`、`uploads/` 可寫 |
| Web 伺服器 | Apache / Nginx（均無需 rewrite 也可運行，見[路由](#7-路由與訪問方式)） |
| 郵件功能 | 可選，需可用的 SMTP 服務（開啟後支持郵箱註冊驗證碼與找回密碼） |

---

## 5. 安裝與部署

### 5.1 快速開始（SQLite，零配置）

1. 將項目文件放到 Web 根目錄（或子目錄）。
2. 訪問 `install.php`（例如 `http://your-domain/install.php`）。
3. 安裝嚮導流程：
   - **語言選擇** → **授權協議**（需同意）
   - **第 1 步 資料庫**：選擇 `SQLite`，使用默認路徑 `data/forum.db` 即可。
   - **第 2 步 環境檢測**：確認 PHP 版本、PDO、資料庫擴展、`data` 目錄可寫均通過。
   - **第 3 步 站點配置**：填寫站點名稱（可填副標題），按需啟用 SMTP。
   - **第 4 步 完成**：點擊「開始安裝」，系統自動建表並寫入默認數據。
4. 安裝完成後，**第一個註冊的帳號自動成為管理員**（超級管理員）。
5. 登錄後訪問後臺進行版塊、角色、權限等詳細配置。

### 5.2 使用 MySQL / PostgreSQL

- 在「資料庫」步驟選擇 `MySQL` 或 `PostgreSQL`，填寫主機、埠、庫名、用戶名、密碼。
- 安裝程序會自動測試連接；MySQL 會在庫不存在時嘗試自動創建。
- 如缺少對應 PDO 擴展，嚮導會給出 `php.ini` 中開啟擴展的具體步驟。

### 5.3 Web 伺服器示例

**Nginx**（推薦，支持美化 URL）：

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

**Apache**：系統默認兼容 `index.php?route=xxx` 方式；如需美化 URL 可放置 `.htaccess` 重寫到 `index.php`。

> 即使不做任何重寫配置，系統也能通過 `index.php?route=xxx`、`index.php?s=xxx`、以及 `REQUEST_URI` 兜底三種方式解析路由，詳見下一節。

---

## 6. 配置說明

安裝生成的配置位於 **`data/site_config.php`**（由 `app/includes/config.php` 自動加載）。常用常量：

| 常量 | 說明 |
| --- | --- |
| `DB_TYPE` | `sqlite` / `mysql` / `pgsql` |
| `DB_FILE` | SQLite 文件路徑（僅 SQLite） |
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` | 遠程資料庫（MySQL/PostgreSQL） |
| `SITE_NAME` / `SITE_SLOGAN` | 站點名稱 / 副標題 |
| `SMTP_ENABLED` / `SMTP_HOST` / `SMTP_PORT` / `SMTP_USER` / `SMTP_PASS` / `SMTP_ENCRYPTION` / `SMTP_FROM` / `SMTP_FROM_NAME` | 郵件服務配置 |

`app/includes/config.php` 中還定義了業務可調參數（如分頁大小、積分規則、Cookie 策略、時區等），可按需修改：

- `POSTS_PER_PAGE` / `REPLIES_PER_PAGE`：分頁大小。
- `CHECKIN_*`：籤到積分/金幣規則與裡程碑獎勵。
- `POST_POINTS` / `REPLY_POINTS` / `*_RECEIVED_POINTS`：內容貢獻積分。
- `POINTS_DAILY_*_CAP`：每日積分上限（防刷）。
- `COOKIE_SECURE` / `CRED_KEY_COOKIE_DAYS`：Cookie 安全與有效期。
- `error_reporting` / `display_errors`：本地（`127.0.0.1`）顯示錯誤，生產環境僅記錄 `data/error.log`。

---

## 7. 路由與訪問方式

入口 `index.php` 兼容多種訪問形態，無需伺服器 rewrite 也能運行：

| 形態 | 示例 |
| --- | --- |
| `route` 參數 | `/index.php?route=forum` |
| `s` 參數（部分 Nginx） | `/index.php?s=forum` |
| 美化 URL（try_files 兜底） | `/forum`、`/post`、`/admin/users` |

**前臺路由映射**（節選，對應 `app/controllers/`）：

| 路由 | 頁面 | 路由 | 頁面 |
| --- | --- | --- | --- |
| `home` | 首頁 | `search` | 搜索 |
| `forum` | 版塊列表 | `profile` | 個人資料 |
| `post` | 主題詳情 | `favorites` | 我的收藏 |
| `new_post` | 發帖 | `checkin` | 籤到 |
| `login` / `register` | 登錄 / 註冊 | `pm` | 私信 |
| `notifications` | 通知 | `report` / `appeal` | 舉報 / 申訴 |
| `forgot_password` / `reset_password` | 找回密碼 | `banned` | 封禁提示頁 |
| `privacy` / `terms` / `disclaimer` / `service` | 站點頁面 | `logout` | 退出登錄 |

**後臺**：以 `/admin` 開頭 → `app/admin/controllers/*`；後臺 AJAX 以 `/admin/api` 開頭 → `app/admin/api/*_ajax.php`。

未安裝時訪問前臺會自動跳轉 `install.php`；已安裝後訪問 `/install` 會自動校驗資料庫完整性。

---

## 8. 資料庫設計

核心表（SQLite 方言，PostgreSQL/MySQL 由安裝器翻譯）。首次安裝時由 `init_db()` 創建核心表，其餘由運行期 `ensure_*_table()` 按需補全（保證升級兼容）。

| 表 | 作用 |
| --- | --- |
| `users` | 用戶（用戶名/郵箱/密碼/積分/金幣/角色/籤到/封禁禁言狀態） |
| `forum_categories` | 版塊分類 |
| `forums` | 版塊（歸屬分類、圖標、主題數、帖子數、最後發帖） |
| `posts` | 主題帖（標題/內容/瀏覽/回複數/置頂/加精/鎖帖） |
| `replies` | 回復（樓層/引用/引用內容，樓中樓） |
| `checkins` | 籤到記錄 |
| `user_points_log` | 積分明細流水 |
| `favorites` | 收藏 |
| `user_groups` | 用戶組（按積分區間自動匹配頭銜/等級） |
| `roles` / `user_roles` | 權限角色與用戶-角色關聯（RBAC） |
| `medals` / `user_medals` | 勳章與用戶獲得記錄 |
| `announcements` | 公告 |
| `site_pages` | 站點頁面（隱私/條款/免責/服務條款等，可後臺編輯） |
| `site_settings` | 站點設置鍵值對（後臺可編輯） |
| `pm_conversations` / `pm_messages` | 私信會話與消息 |
| `notifications` | 系統通知 |
| `reports` | 內容舉報 |
| `ban_appeals` | 封禁申訴 |
| `password_reset_requests` | 密碼重置申請 |
| `sensitive_words` / `sensitive_word_whitelist` / `sensitive_word_logs` / `sensitive_word_status_logs` | 敏感詞庫/白名單/命中日誌/狀態日誌 |
| `mail_logs` / `mail_bounce_config` / `mail_bounce_logs` | 郵件發送日誌/退信配置/退信記錄 |
| `traffic_stats` / `traffic_visitors` | 流量統計與訪客 |

> **遷移機制**：`auto_migrate()` 在運行期按需創建缺失表與索引（`ensure_*_table()` / `ensure_db_indexes()`），並以 `data/db_version.lock` 記錄版本，保證老版本升級時不破壞存量數據。

---

## 9. 前臺功能

- **瀏覽**：首頁聚合（統計、公告、版塊、熱門/最新）、版塊帖子列表、主題詳情（含引用回復、分頁）、搜索。
- **發布**：發帖、回復（支持 BBCode、表情、引用、圖片上傳）、編輯資料、收藏。
- **帳戶**：註冊（可選郵箱驗證）、登錄（記住密碼）、找回/重置密碼、強制改密、封禁申訴。
- **互動**：私信（輪詢實時未讀）、系統通知、每日籤到、積分/金幣與等級展示、勳章牆。

---

## 10. 後臺管理

入口：`/admin`（對應 `app/admin/controllers/`，布局見 `app/admin/layout/`）。主要功能：

| 模塊 | 文件（app/admin/controllers） |
| --- | --- |
| 控制臺 / 系統狀態 | `index.php`、`system_status.php`、`traffic_monitor.php` |
| 站點設置 | `site_settings.php`、`site_pages.php` |
| 內容管理 | `forums.php`、`posts.php`、`replies.php`、`announcements.php` |
| 用戶管理 | `users.php`、`user_edit.php`、`user_groups.php`、`roles.php`、`user_ban.php`、`user_mute.php` |
| 審核與合規 | `reports.php`、`ban_appeals.php`、`password_reset_requests.php`、`sensitive_words.php`、`sensitive_word_logs.php` |
| 勳章 | `medals.php` |
| 郵件 | `mail_center.php`（日誌/統計/通知/退信配置） |
| 運維 | `backup.php` |

後臺大量操作通過 `app/admin/api/*_ajax.php` 以 JSON 返回，前端異步調用，並提供待辦計數（`pending_counts_ajax.php`）、用戶風險詳情（`user_risk_detail_ajax.php`）、系統診斷（`diag_auth.php`）等輔助接口。

---

## 11. API 接口

**公共接口**（`public/api/`，返回 JSON）：

| 文件 | 說明 |
| --- | --- |
| `home_realtime.php` | 首頁實時數據（緩存聚合） |
| `pm_unread.php` | 私信未讀數與最新一條摘要（輪詢，2s 服務端緩存） |
| `pm_poll.php` | 私信長輪詢 |
| `post_replies_count.php` | 主題回複數實時查詢 |
| `check_ban_status.php` | 當前用戶封禁/禁言狀態 |
| `upload_image.php` | 圖片上傳 |

**後臺接口**（`app/admin/api/`，`*_ajax.php`）：backup、ban_appeals、bounce、diag_auth、mail_notify、mail_stats、pending_counts、posts、replies、reports、sensitive_logs、sensitive_words、system_status、traffic、user_detail、user_risk_detail、users、users_bulk、users_export_csv。

> 接口普遍使用 `realtime_cache($key, $ttl, $callback)` 做短緩存，避免高頻輪詢壓垮資料庫。

---

## 12. 主題與定製

- **樣式變量**：`public/css/tokens.css` 定義 CSS 變量（顏色、圓角、間距），改主題色只需調整該文件。
- **明暗主題**：`public/css/dark.css` 為暗色主題，通過 `<body>` 切換（系統/用戶偏好）。
- **頁面樣式**：`style.css`、`base.css`、`header.css`、`pm.css`、`profile.css`、`utilities.css`。
- **腳本**：`public/js/main.js`（全局交互）、`editor.js`（發帖編輯器）、`lightbox.js`（圖片燈箱）。
- **圖標**：版塊圖標與 UI 圖標使用 SVG / Emoji（`ui_icon()`、`forum_icon()` 等函數生成）。
- **語言包**：`app/includes/languages/*.php`，數組式鍵值，新增語言只需新增一個語言包文件並在 `config.php` 的 `get_available_languages()` 中登記。

---

## 13. 安全機制

- **會話安全**：`session.cookie_httponly`、`samesite=Lax`，HTTPS 時自動啟用 `secure`；`remember` Cookie 可配置僅 HTTPS。
- **CSRF**：所有寫操作經 `validate_csrf()` / `csrf_token()` 校驗。
- **密碼**：使用 PHP `password_hash`（bcrypt）存儲。
- **安全響應頭**：`X-Content-Type-Options: nosniff`、`X-Frame-Options: SAMEORIGIN`、`Referrer-Policy: strict-origin-when-cross-origin`。
- **輸出轉義**：`e()` 統一轉義輸出，防止 XSS。
- **內容審核**：敏感詞引擎（Trie + Aho-Corasick）三級處理 + 白名單 + 命中日誌；`assess_post_risk()` 評估發帖風險。
- **封禁/禁言**：`banned_until` / `muted_until` 支持自動過期（`auto_expire_user_status()`），過期自動恢復。
- **用戶風險**：`compute_user_risk()` 綜合行為計算風險等級，輔助後臺治理。
- **安裝保護**：存在 `installed.lock` 後再次訪問安裝頁會校驗資料庫完整性，防止重複安裝破壞數據；SQLite 路徑做了目錄遍歷防護。

---

## 14. 二次開發指南

**新增前臺頁面**

1. 在 `app/controllers/` 下新建 `my_page.php`，首行 `require_once APP_ROOT . 'app/includes/functions.php';`，按約定 `include` `header.php` / `footer.php`。
2. 在 `index.php` 的 `$routes` 數組中添加 `'my_page' => 'my_page'` 映射。
3. 訪問 `/my_page` 即可。

**新增公共 API**

- 在 `public/api/` 下新建 `xxx.php`，返回 `json_encode([...], JSON_UNESCAPED_UNICODE)`；`index.php` 的 `/api/` 分支會自動加載。

**新增後臺頁面 / 接口**

- 頁面：`app/admin/controllers/xxx.php`（可 `require` `app/admin/layout/admin-init.php` 復用後臺布局與鑑權）。
- 接口：`app/admin/api/xxx_ajax.php`，通過 `/admin/api/xxx` 訪問。

**資料庫操作**

- 讀取：`$db = get_db();` 後用 PDO 預處理。
- 新增表：在 `app/includes/db.php` 增加 `ensure_xxx_table(PDO $db)` 函數並在 `auto_migrate()` 中調用，保證冪等與升級兼容（使用 `CREATE TABLE IF NOT EXISTS`）。

**常用全局函數**（`app/includes/functions.php`）

- 鑑權：`is_logged_in()`、`current_user()`、`require_login()`、`require_admin()`、`has_permission()`、`is_admin()`
- 輸出/URL：`e()`、`t()`（翻譯）、`redirect()`、`site_url()`、`avatar_url()`
- 內容：`bbcode()`、`safe_content()`、`linkify()`、`ui_icon()`、`forum_icon()`
- 積分：`add_user_points()`、`get_user_daily_points()`、`get_user_group()`
- 通知：`send_notification()`、`get_unread_notification_count()`
- 緩存：`realtime_cache()`

---

## 15. 數據備份與維護

- **資料庫備份**：後臺「備份」(`backup.php`) 調用 `app/includes/backup_manager.php`；SQLite 也可直接複製 `data/forum.db`。
- **遷移與升級**：新版本解壓覆蓋代碼後，運行期 `auto_migrate()` 會自動補全表/索引，無需手工改庫。
- **日誌**：錯誤記錄在 `data/error.log`；安裝期 DDL 執行明細可通過 `get_ddl_install_log()` 查看（安裝失敗時嚮導會展示）。
- **退信處理**：`app/includes/bounce_processor.php` 處理郵件退信並更新用戶郵箱狀態。
- **流量統計**：`track_visit()` 記錄訪問，後臺「流量監控」可查看。

---

## 16. 常見問題 (FAQ)

**Q1. 安裝時提示「data 目錄不可寫」？**
創建並賦予寫權限：`mkdir data && chmod 755 data`（Windows 下在目錄屬性的「安全」中給 Web 進程寫權限）。

**Q2. 想換資料庫類型（如 SQLite → MySQL）？**
目前安裝嚮導在首次安裝時確定資料庫類型。切換到遠程資料庫建議：備份數據 → 修改 `data/site_config.php` 的 `DB_*` 常量 → 在目標庫建庫 → 重新導入數據。生產環境請先備份。

**Q3. 啟用郵件後註冊需要郵箱驗證碼？**
在「站點配置 / 後臺郵件中心」啟用 SMTP 並正確填寫後，註冊與找回密碼將啟用郵箱驗證碼流程；未啟用 SMTP 時走普通註冊流程。

**Q4. 如何新增語言？**
在 `app/includes/languages/` 複製 `zh-CN.php` 為 `xx.php` 並翻譯，再到 `app/includes/config.php` 的 `get_available_languages()` 增加該語言項即可。

**Q5. 升級後頁面報錯或表缺失？**
訪問一次前臺/後臺觸發 `auto_migrate()` 自動補表；若仍異常，查看 `data/error.log`。必要時可刪除 `data/db_version.lock` 強制重新檢查遷移（不會刪除數據）。

**Q6. 忘記管理員密碼？**
可通過「找回密碼」（需 SMTP）重置；或直接在資料庫 `users` 表用 `password_hash()` 重置對應用戶的 `password` 欄位。

**Q7. 拼圖驗證看起來對齊了但提示失敗？**
請先強制刷新瀏覽器（`Ctrl+F5`）以加載最新 `captcha.js`；若容器被 CSS 壓縮導致舞台寬度不是 300px，系統會自動按比例換算座標。也可在後臺「站點配置 → 驗證方式」臨時切到「點選文字」驗證排查。

**Q8. 點選文字驗證的漢字顯示為方框？**
點選文字依賴 GD 與字體文件渲染。默認使用系統字體，中文顯示不佳時請在 `app/captcha/fonts/` 放置中文字體（如 `SourceHanSansSC-Regular.otf`），系統將自動優先使用。

---

> 文檔基於項目源碼（`index.php`、`install.php`、`app/includes/*`、`public/*`）整理，版本 `1.3.4-beta`。
> 如與代碼實現不符，請以代碼與安裝嚮導提示為準。

---

## 其他語言版本

- [简体中文 (README.md)](README.md)
- [English (README.en.md)](README.en.md)
