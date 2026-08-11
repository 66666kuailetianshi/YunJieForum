# 雲界論壇 (Cloud Forum)

> **當前狀態：正式版**（`v1.5.0`）｜ 輕量級社區論壇系統 · PHP + SQLite · 開箱即用

**[简体中文](README.md) · [English](README.en.md)**

`雲界論壇` 是一套純 PHP 編寫的輕量級社區論壇（BBS）系統，默認使用 SQLite 文件資料庫，無需獨立部署資料庫伺服器即可運行，適合個人博客社區、興趣小組、內網知識庫等場景。系統內置用戶體系、版塊/帖子/回復、私信、通知、籤到積分、勳章角色、內容審核（敏感詞）、郵件與流量統計等完整功能，並提供可視化的安裝嚮導與後臺管理。

- **當前版本：** `1.5.0`
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
| 系統更新 | 「系統更新中心」支援手動/自動檢查並應用版本更新：下載 → 校驗 SHA256 雜湊 → 自動備份 → 覆蓋升級，詳見[第 10.3 節](#103-系統更新中心-update_center) |
| 多語言 | 內置 `簡體中文 / 繁體中文 / English`，按 URL、Cookie、配置、瀏覽器語言自動識別 |
| 主題 | 基於 CSS 變量的明暗雙主題（light / dark），可改色與換膚 |
| 工單系統 | 前臺「意見反饋」與後臺工單系統統一處理站點問題（bug 等），支援來源篩選（用戶 / 管理員）與狀態流轉（待處理 → 處理中 → 已解決 / 已關閉），新提交自動通知管理員 |
| 郵箱披露 | 管理員默認看不到用戶郵箱（隱私保護），可在用戶管理中發起披露申請（需說明原因），超管審核通過後可見；已有待審核/未消費申請時自動鎖定申請入口，防止重複提交 |
- **人機驗證**：
  - 獨立模組位於 `app/captcha/`，無需第三方服務。
  - 支援「滑塊拼圖」「點選文字」與「推理交換」三種挑戰模式，可在後台一鍵切換或設定為智能混合。
  - 顯示方式：內嵌式 / 彈窗式；行為打分支援使用者通過時無感通過。

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

> 下方樹為**實測得**的專案佈局（`app/` 含 129 個 PHP 檔案 + 各資源檔案）。帶 `#` 註釋的為本專案實際存在的檔案。

```
雲界論壇/
├── index.php                  # 前臺入口 / 路由分發（單一入口，解析 route/s/REQUEST_URI）
├── install.php               # 安裝嚮導（資料庫→環境檢測→站點配置→完成，前置語言與授權協議）
├── LICENSE                    # 開源許可證文字
├── README.md / README.en.md / README.zh-TW.md   # 三語專案文件
│
├── app/                       # 應用核心（129 個 PHP）
│   ├── controllers/           # 前臺頁面控制器（27 個，每個對應一條前臺路由）
│   │   ├── home.php           # 首頁（聚合統計/公告/版塊/熱門）
│   │   ├── forum.php          # 版塊帖子列表
│   │   ├── post.php           # 主題帖詳情（引用/分頁）
│   │   ├── new_post.php       # 發帖
│   │   ├── login.php          # 登入（含記住密碼 Web Crypto 加密）
│   │   ├── register.php / logout.php
│   │   ├── profile.php / favorites.php / search.php
│   │   ├── pm.php             # 私信會話
│   │   ├── notifications.php / notification_read.php   # 通知列表 / 標記已讀
│   │   ├── checkin.php        # 每日籤到
│   │   ├── report.php / appeal.php / banned.php   # 舉報 / 申訴 / 封禁提示
│   │   ├── forgot_password.php / reset_password.php / force_change_password.php
│   │   ├── send_email_code.php / send_password_change_code.php   # 郵箱驗證碼發送
│   │   ├── privacy.php / terms.php / disclaimer.php / service.php   # 站點頁面（可後臺編輯）
│   │   └── ticket.php           # 意見反饋（提交 / 列表 / 詳情 / 回覆）
│   │
│   ├── admin/                 # 後臺（獨立入口前綴 /admin）
│   │   ├── controllers/       # 後臺頁面（29 個）
│   │   │   ├── index.php              # 控制台首頁
│   │   │   ├── system_status.php      # 系統狀態監控（CPU/記憶體/溫度/網路/磁碟/顯卡）
│   │   │   ├── traffic_monitor.php     # 流量監控
│   │   │   ├── site_settings.php / site_pages.php
│   │   │   ├── forums.php / posts.php / replies.php / announcements.php
│   │   │   ├── users.php / user_edit.php / user_groups.php / roles.php / user_create.php
│   │   │   ├── user_ban.php / user_mute.php
│   │   │   ├── reports.php / ban_appeals.php / password_reset_requests.php
│   │   │   ├── sensitive_words.php / sensitive_word_logs.php
│   │   │   ├── tickets.php / email_disclosure.php   # 工單系統 / 郵箱披露申請審核
│   │   │   ├── medals.php / mail_center.php / backup.php
│   │   │   ├── data_migration.php       # 數據遷移與還原（匯出/匯入，支援 ZIP 含頭像）
│   │   │   ├── update_center.php        # 系統更新中心（檢查/手動更新/自動更新設定）
│   │   │   └── captcha_debug.php       # 人機驗證偵錯台
│   │   ├── api/               # 後臺 AJAX 接口（20 個，命名 *_ajax.php）
│   │   │   ├── system_status_ajax.php   # 系統狀態輪詢採集（含診斷 ?diag=1）
│   │   │   ├── traffic_ajax.php / pending_counts_ajax.php
│   │   │   ├── posts_ajax.php / replies_ajax.php / reports_ajax.php
│   │   │   ├── users_ajax.php / users_bulk_ajax.php / users_export_csv.php
│   │   │   ├── user_detail_ajax.php / user_risk_detail_ajax.php
│   │   │   ├── ban_appeals_ajax.php / sensitive_words_ajax.php / sensitive_logs_ajax.php
│   │   │   ├── backup_ajax.php / mail_stats_ajax.php / mail_notify_ajax.php
│   │   │   ├── bounce_ajax.php / data_migration_ajax.php
│   │   │   ├── update_ajax.php          # 系統更新中心（檢查/下載/校驗/更新）
│   │   │   └── (其餘見第 11 節)
│   │   └── layout/            # 後臺佈局
│   │       ├── admin-init.php # 後臺鑑權與初始化（被各後臺頁面 require）
│   │       ├── admin-helpers.php # 後臺公共輔助函數（帖子標誌/工單連結分流等）
│   │       ├── header.php / footer.php
│   │
│   ├── includes/              # 全局支撐程式碼
│   │   ├── config.php         # 全局配置常量、多語言載入、安全響應頭、業務參數
│   │   ├── functions.php      # 全局函數（鑑權/CSRF/格式化/積分/郵件碼/BBCode…）
│   │   ├── db.php             # 核心 Schema、建表/遷移/初始化/默認數據
│   │   ├── database/          # 資料庫驅動抽象層
│   │   │   ├── DatabaseFactory.php    # 依 DB_TYPE 建立驅動
│   │   │   ├── AbstractDriver.php     # PDO 封裝、跨庫查詢輔助、重連
│   │   │   ├── SQLiteDriver.php / MySQLDriver.php / PostgreSQLDriver.php
│   │   ├── languages/         # 語言包
│   │   │   ├── zh-CN.php / zh-TW.php / en.php   # 主語言包（核心鍵）
│   │   │   └── extras/        # 分批擴展語言包（按語言子目錄，安裝時合併載入）
│   │   │       ├── zh-CN/  zh-TW/  en/          # 各語言專屬擴展（admin_ajax/batch_*/mail_center…）
│   │   ├── mailer.php         # 原生 fsockopen SMTP 發送器 + 郵件日誌
│   │   ├── bounce_processor.php  # 退信處理
│   │   ├── backup_manager.php    # 資料庫備份
│   │   ├── update_center.php    # 系統更新中心（檢查/下載/校驗/備份/覆蓋 共享邏輯）
│   │   ├── compat.php         # 無 mbstring 時的兼容層
│   │   └── header.php / footer.php
│   │
│   ├── components/
│   │   └── sensitive_filter/  # 敏感詞過濾引擎
│   │       ├── SensitiveFilter.php   # Trie + Aho-Corasick 匹配內核
│   │       └── helper.php            # 輔助函數
│   │
│   └── captcha/               # 人機驗證模組（無第三方依賴，GD 生成資源）
│       ├── core.php           # 驗證邏輯、挑戰生成與校驗
│       ├── api.php            # 驗證會話/題目下發接口
│       ├── serve.php          # 資源入口（captcha.js / captcha.css / 背景圖）
│       ├── captcha.js         # 前端互動（滑塊/點選/交換）
│       └── captcha.css        # 驗證組件樣式
│
├── public/                    # Web 可存取的靜態資源與公共接口
│   ├── api/                   # 公共 JSON 接口（6 個，輪詢/上傳）
│   │   ├── home_realtime.php / pm_unread.php / pm_poll.php
│   │   ├── post_replies_count.php / check_ban_status.php / upload_image.php
│   ├── css/                   # 樣式（10 個）
│   │   ├── tokens.css         # CSS 變數（顏色/圓角/間距）—— 換膚改這裡
│   │   ├── style.css / base.css / dark.css / components.css   # 主樣式 / 基礎 / 暗色 / 組件
│   │   ├── header.css / pm.css / profile.css / utilities.css
│   │   └── admin.css        # 後臺樣式
│   ├── js/                    # 腳本（3 個）
│   │   ├── main.js            # 全局互動（導覽/校驗/提示/下拉）
│   │   ├── editor.js          # 發帖編輯器（BBCode/上傳）
│   │   └── lightbox.js        # 圖片燈箱
│   └── images/
│       └── logo.svg           # 站點 Logo
│
├── data/                      # 運行時目錄（安裝時創建，需可寫）
│   ├── site_config.php        # 安裝生成的配置（DB_*/SITE_*/SMTP_* 常量）
│   ├── installed.lock         # 安裝鎖（存在表示已安裝）
│   ├── forum.db               # SQLite 資料庫（默認）
│   ├── error.log              # 錯誤日誌
│   └── db_version.lock        # Schema 遷移版本鎖
│
├── uploads/                   # 上傳檔案（安裝時創建，需可寫）
│   ├── avatars/               # 用戶頭像
│   └── images/                # 帖子圖片
│
└── tools/                     # 開發輔助腳本（後臺 CSS 分段建置等）
```

> `data/` 與 `uploads/` 在首次安裝時創建。**需對 Web 服務器進程可寫**。請確認其權限（如 `chmod 755 data uploads`）。`app/`、`public/`、`index.php`、`install.php` 無需寫權限。

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

# ===== 安全規則（必須）=====
# data/ 目錄存放資料庫、配置憑證、備份與會話，禁止任何 Web 訪問
location ^~ /data/ {
    deny all;
}
# 封禁敏感檔案副檔名的直接下載（僅匹配這些後綴，不影響 public/ 與 uploads/ 下的圖片等正常檔案）
location ~* \.(db|sqlite|sqlite3|sql|zip|gz|log|cache|lock)$ {
    deny all;
}
```

> ⚠️ **安全提示**：項目自帶的 `.htaccess` 僅對 **Apache** 生效。**Nginx / 寶塔面板** 用戶不讀取 `.htaccess`，必須按上面的示例在伺服器（站點）配置中手工添加對應的 `deny` 規則，否則 `data/` 下的資料庫、配置與備份檔案可被匿名下載。

**Apache**：系統默認兼容 `index.php?route=xxx` 方式；如需美化 URL 可放置 `.htaccess` 重寫到 `index.php`。項目已自帶根目錄與 `data/` 目錄的 `.htaccess` 安全封禁規則（Apache 下自動生效）。

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
| `ticket` | 意見反饋（提交 / 列表 / 詳情 / 回覆） | | |

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
| `email_disclosure_requests` | 郵箱披露申請（申請人/目標用戶/原因/審核狀態/查看狀態） |
| `site_pages` | 站點頁面（隱私/條款/免責/服務條款等，可後臺編輯） |
| `site_settings` | 站點設置鍵值對（後臺可編輯） |
| `pm_conversations` / `pm_messages` | 私信會話與消息 |
| `notifications` | 系統通知 |
| `reports` | 內容舉報 |
| `ban_appeals` | 封禁申訴 |
| `password_reset_requests` | 密碼重置申請 |
| `sensitive_words` / `sensitive_word_whitelist` / `sensitive_word_logs` / `sensitive_word_status_logs` | 敏感詞庫/白名單/命中日誌/狀態日誌 |
| `tickets` / `ticket_replies` | 工單與跟進回覆（來源：user 前臺反饋 / admin 管理員工單） |
| `mail_logs` / `mail_bounce_config` / `mail_bounce_logs` | 郵件發送日誌/退信配置/退信記錄 |
| `traffic_stats` / `traffic_visitors` | 流量統計與訪客 |

> **遷移機制**：`auto_migrate()` 在運行期按需創建缺失表與索引（`ensure_*_table()` / `ensure_db_indexes()`），並以 `data/db_version.lock` 記錄版本，保證老版本升級時不破壞存量數據。

---

## 9. 前臺功能

- **瀏覽**：首頁聚合（統計、公告、版塊、熱門/最新）、版塊帖子列表、主題詳情（含引用回復、分頁）、搜索。
- **發布**：發帖、回復（支持 BBCode、表情、引用、圖片上傳）、編輯資料、收藏。
- **帳戶**：註冊（可選郵箱驗證）、登錄（記住密碼）、找回/重置密碼、強制改密、封禁申訴。
- **互動**：私信（輪詢實時未讀）、系統通知、每日籤到、積分/金幣與等級展示、勳章牆。
- **反饋**：意見反饋（提交問題工單、查看處理進度、跟進回覆），新工單自動通知管理員。

---

## 10. 後臺管理

入口：`/admin`（對應 `app/admin/controllers/`，布局見 `app/admin/layout/`）。主要功能：

| 模塊 | 文件（app/admin/controllers） |
| --- | --- |
| 控制臺 / 系統狀態 | `index.php`、`system_status.php`、`traffic_monitor.php` |
| 站點設置 | `site_settings.php`、`site_pages.php` |
| 內容管理 | `forums.php`、`posts.php`、`replies.php`、`announcements.php` |
| 用戶管理 | `users.php`、`user_edit.php`、`user_groups.php`、`roles.php`、`user_ban.php`、`user_mute.php`、`user_create.php` |
| 審核與合規 | `reports.php`、`ban_appeals.php`、`password_reset_requests.php`、`sensitive_words.php`、`sensitive_word_logs.php`、`email_disclosure.php` |
| 工單系統 | `tickets.php`（前臺反饋與管理員工單統一處理，支援來源篩選與狀態流轉） |
| 勳章 | `medals.php` |
| 郵件 | `mail_center.php`（日誌/統計/通知/退信配置） |
| 運維 | `backup.php`、`data_migration.php`（數據遷移與還原）、`update_center.php`（系統更新中心） |

後臺大量操作通過 `app/admin/api/*_ajax.php` 以 JSON 返回，前端異步調用，並提供待辦計數（`pending_counts_ajax.php`）、用戶風險詳情（`user_risk_detail_ajax.php`）等輔助接口。

### 10.1 系統狀態監控（system_status）

入口 `/admin/system_status`，由 `app/admin/controllers/system_status.php` 渲染、`app/admin/api/system_status_ajax.php` 採集。前端以 AJAX 輪詢方式並行拉取三類數據：

| 數據類型 | 端點參數 | 輪詢間隔 | 採集函數 |
| --- | --- | --- | --- |
| 靜態信息 | `?static=1` | 僅首次（1 小時緩存） | `ss_get_cpu_info()` / `ss_get_memory_banks()` / `ss_get_disk_hardware()` / `ss_get_gpu_info()` / `ss_get_motherboard_info()` / `ss_get_network_interfaces()` / `ss_get_php_info()` |
| 動態數據 | 默認 | 2 秒 | `ss_sample_cpu_and_memory()`（CPU 負載 + 記憶體佔用） |
| 溫度 | `?temps=1` | 3 秒 | `ss_get_temperatures()` |

**多路 CPU 支援**：採集層通過 `ss_wmi_query()` 拉取**全部** `Win32_Processor` 行（雙路/多路服務器每顆物理 CPU 一行），聚合 `NumberOfCores` 與 `NumberOfLogicalProcessors`，並標註路數（如 `2 x Intel Xeon E5-2673 v4`）。CPU 實時負載取各路 `LoadPercentage` 的平均值。返回值含 `sockets` 欄位，前端展示「X 路處理器」。

**溫度採集鏈路**（Windows，按優先級回退，共 8 層）：

1. `root/OpenHardwareMonitor` WMI（最準確，需安裝 OpenHardwareMonitor）
2. `wmic` 命令行 OpenHardwareMonitor
3. `MSAcpi_ThermalZoneTemperature` COM（ACPI 溫度區，十分之一開爾文換算）
4. `wmic` 命令行 `MSAcpi_ThermalZoneTemperature`
5. PowerShell CIM `MSAcpi_ThermalZoneTemperature`
6. `Win32_TemperatureProbe`（部分服務器/主機板廠商提供）
7. `MSStorageDriver_ATAPISmartData`（解析硬碟 SMART 屬性 194/190 獲取硬碟溫度）
8. PowerShell CIM `Win32_TemperatureProbe` 兜底

> 若全部失敗，溫度卡片顯示「未能獲取溫度數據（需硬件支持或安裝 OpenHardwareMonitor）」。服務器 BMC/IPMI 場景下，安裝 OpenHardwareMonitor 可啟用最完整的傳感器讀數。

診斷端點：`/admin/api/system_status_ajax?diag=1` 返回各採集通道的可用性（COM/FFI/PowerShell）、CPU/GPU/記憶體原始數據及緩存文件清單，用於排查採集失敗原因。

### 10.2 數據遷移與還原（data_migration）

入口 `/admin/data_migration`，由 `app/admin/controllers/data_migration.php` 渲染、`app/admin/api/data_migration_ajax.php` 提供匯出/匯入接口。用於在不同伺服器或重裝後遷移站點數據，並支援頭像等上傳文件隨庫一併遷移（打包為 ZIP）。

**匯出（三種格式，依當前資料庫類型提供對應項）**

| 格式 | 文件 | 說明 |
| --- | --- | --- |
| 通用 JSON | `*.json` | 跨資料庫相容的通用格式，攜帶來源庫類型標記（`source_driver`） |
| SQLite SQL | `*.zip` | 當前庫為 SQLite 時可用；ZIP 內含 `database_backup.sql` + `uploads/`（頭像等）+ `manifest.json` |
| MySQL SQL | `*.zip` | 當前庫為 MySQL 時可用；結構同上 |

> 匯出檔名預設使用英文（`yunjie_backup_YYYYMMDD_HHMMSS.*`），避免 Windows 檔案總管因中文編碼出現亂碼；中文原名經 `filename*` 參數供現代瀏覽器識別顯示。

**匯入**

- 支援 `.json`、`.sql`、`.zip` 三種文件。
- **跨庫保護**：系統自動讀取文件來源庫類型標記（SQL 文件的 `-- DB-TYPE:` 註釋，或 JSON 的 `source_driver`），與**當前庫類型不一致時拒絕匯入**，防止不同資料庫之間互相遷移導致結構不相容。
- **上傳文件還原**：匯入 `.zip` 時自動安全解壓（禁止路徑穿越）→ 將包內 `uploads/` 還原到專案目錄 → 執行 SQL；匯入後頭像、帖子圖片等不會遺失。
- **匯入前快照**：每次匯入前自動建立資料庫快照，失敗可回滾。
- **進度提示**：匯入過程顯示階段性進度條（ZIP：上傳 → 解析 → 還原資源 → 快照 → 寫入資料庫；其他：上傳 → 解析 → 快照 → 寫入資料庫）。

### 10.3 系統更新中心（update_center）

入口 `/admin/update_center`，由 `app/admin/controllers/update_center.php` 渲染、`app/admin/api/update_ajax.php` 提供檢查/更新接口，核心邏輯位於 `app/includes/update_center.php`。用於在線檢查並應用雲界論壇的版本更新，支援**手動**與**自動**兩種方式。

**更新設定**

| 設定項 | 說明 |
| --- | --- |
| 更新源位址 | 兩種格式：① 目錄位址（如 `https://example.com/updates`）→ 自動拼接 `/{通道}/version.json`；② 直鏈檔案（`.json/.txt/.yml/.yaml` 結尾）→ 直接作為版本資訊讀取（JSON 或純文字版本號）。留空則無法檢查更新。 |
| 更新通道 | `stable`（穩定版）/ `beta`（測試版）/ `dev`（開發版）。 |
| 嚴格校驗 SSL 憑證 | 預設關閉。更新源使用自簽名憑證（如個人伺服器）時應保持關閉；僅正規 CA 簽發時才開啟。 |
| 跳過雜湊校驗 | 預設強制校驗更新包 SHA256/SHA1 雜湊以防篡改；僅當更新源無法提供 `package_hash` 且完全信任該源時，才可開啟跳過（有被篡改覆蓋的風險）。 |
| 啟用自動更新 | 開啟後按「自動更新間隔」自動檢查，並在發現新版本時自動下載、備份並覆蓋升級。 |
| 自動更新間隔（小時） | 距上次檢查超過該小時數後，再次訪問後臺即觸發自動檢查與安裝。建議 24（每天一次）。 |

**更新流程（安全優先）**

手動點擊「立即更新」或自動更新觸發後，均走同一套原子化流程，任意一步失敗都會回退、絕不留下半成品：

1. **檢查**：拉取 `{base}/{通道}/version.json`，解析最新版本（支援 JSON 與純文字格式）；`version_compare` 判斷是否可用。
2. **下載**：串流下載更新包到 `data/tmp/`，即時回傳進度（前端進度條：準備 → 下載 → 校驗 → 備份 → 解壓覆蓋 → 完成）。
3. **校驗**：下載完成後嚴格比對 `package_hash`（SHA256/SHA1），不符則立即丟棄並取消更新。
4. **備份**：升級前對現有程式碼（`app/`、`public/` 及入口檔案）整包備份到 `data/backups/update_pre_{時間戳}.zip`，可隨時從「資料備份」恢復。
5. **覆蓋**：解壓更新包到安裝根目錄，期間**禁止路徑穿越**、**禁止覆蓋 `data/`**（保留用戶資料、配置與資料庫）。

> 自動更新同樣走「校驗 + 備份 + 覆蓋」全流程；若更新源未提供 `package_url`，可將更新包命名為 `update.zip` 放在「{通道}/」目錄下由系統自動推導。

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

**後臺接口**（`app/admin/api/`，`*_ajax.php`）：backup、ban_appeals、bounce、data_migration、update、mail_notify、mail_stats、pending_counts、posts、replies、reports、sensitive_logs、sensitive_words、system_status、traffic、user_detail、user_risk_detail、users、users_bulk、users_export_csv。

> 接口普遍使用 `realtime_cache($key, $ttl, $callback)` 做短緩存，避免高頻輪詢壓垮資料庫。

---

## 12. 主題與定製

- **樣式變量**：`public/css/tokens.css` 定義 CSS 變量（顏色、圓角、間距），改主題色只需調整該文件。
- **明暗主題**：`public/css/dark.css` 為暗色主題，通過 `<body>` 切換（系統/用戶偏好）。
- **頁面樣式**：`style.css`、`base.css`、`header.css`、`pm.css`、`profile.css`、`utilities.css`。
- **腳本**：`public/js/main.js`（全局交互）、`editor.js`（發帖編輯器）、`lightbox.js`（圖片燈箱）。
- **圖標**：版塊圖標與 UI 圖標使用 SVG / Emoji（`ui_icon()`、`forum_icon()` 等函數生成）。
- **語言包**：`app/includes/languages/*.php`，數組式鍵值。載入時先 `require` 主包（如 `zh-TW.php`），再從 `extras/{code}/*.php` 分批合併擴展鍵（如 `admin_ajax.php`、`batch_b01.php`、`mail_center.php`），便於按模組維護與按需載入。新增語言：複製 `zh-TW.php` 為 `xx.php` 翻譯主包，在 `extras/xx/` 放擴展包，最後到 `config.php` 的 `get_available_languages()` 登記即可。
- **翻譯函數**：`t($key, $default, $vars)` 從全局 `$LANG` 取文字；缺失時回退 `$default`，再否則返回 key 本身；支援 `{var}` 佔位符替換（如 `t('welcome', '歡迎，{name}', ['name' => $u])`）。

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

- **資料庫備份**：後臺「備份」(`backup.php`) 調用 `app/includes/backup_manager.php`，**預設執行完整資料庫備份**（SQLite 全庫檔案 / MySQL 全庫 `mysqldump`），也支援按指定表匯出；SQLite 也可直接複製 `data/forum.db`。
- **數據遷移**：後臺「數據遷移」(`data_migration.php`) 支援把業務表匯出為 JSON / SQL 格式，再匯入到另一個實例，匯入前會自動建立快照。匯出格式適用場景如下：
  - **通用 JSON**：跨 SQLite / MySQL 環境遷移，支援「合併」與「覆蓋」兩種匯入模式，不帶頭像等上傳檔案。
  - **通用 JSON（ZIP 含頭像）**：跨環境遷移且需要保留頭像、上傳檔案；ZIP 內為 JSON + `uploads/`，支援合併/覆蓋匯入。
  - **SQLite / MySQL 資料庫（ZIP 含頭像）**：同類型資料庫整庫搬遷；ZIP 內為 SQL + `uploads/`，執行 `DROP TABLE + CREATE TABLE`，僅支援覆蓋匯入。
- **全表快照開關**：遷移匯入前預設只備份本次涉及的業務表，以縮短 `mysqldump` 時間、避免代理超時。如需完整資料庫快照，可在 `data/site_config.php` 中定義 `define('MIGRATION_SNAPSHOT_FULL_DB', true);`。
- **遷移與升級**：新版本解壓覆蓋代碼後，運行期 `auto_migrate()` 會自動補全表/索引，無需手工改庫。
- **日誌**：錯誤記錄在 `data/error.log`；安裝期 DDL 執行明細可通過 `get_ddl_install_log()` 查看（安裝失敗時嚮導會展示）。
- **退信處理**：`app/includes/bounce_processor.php` 處理郵件退信並更新用戶郵箱狀態。
- **流量統計**：`track_visit()` 記錄訪問，後臺「流量監控」可查看。

---

## 16. 常見問題 (FAQ)

**Q1. 安裝時提示「data 目錄不可寫」？**
創建並賦予寫權限：`mkdir data && chmod 755 data`（Windows 下在目錄屬性的「安全」中給 Web 進程寫權限）。

**Q2. 想換資料庫類型（如 SQLite → MySQL）？**
目前安裝嚮導在首次安裝時確定資料庫類型。切換到遠程資料庫建議：備份數據 → 修改 `data/site_config.php` 的 `DB_*` 常量 → 在目標庫建庫 → 重新匯入數據。生產環境請先備份。

**Q3. 啟用郵件後註冊需要郵箱驗證碼？**
在「站點配置 / 後臺郵件中心」啟用 SMTP 並正確填寫後，註冊與找回密碼將啟用郵箱驗證碼流程；未啟用 SMTP 時走普通註冊流程。

**Q4. 如何新增語言？**
在 `app/includes/languages/` 複製 `zh-TW.php` 為 `xx.php` 並翻譯，再到 `app/includes/config.php` 的 `get_available_languages()` 增加該語言項即可。

**Q5. 升級後頁面報錯或表缺失？**
訪問一次前臺/後臺觸發 `auto_migrate()` 自動補表；若仍異常，查看 `data/error.log`。必要時可刪除 `data/db_version.lock` 強制重新檢查遷移（不會刪除數據）。

**Q6. 忘記管理員密碼？**
可透過「找回密碼」（需 SMTP）重置；或直接在資料庫 `users` 表用 `password_hash()` 重置對應用戶的 `password` 欄位。

**Q7. 拼圖驗證看起來對齊了但提示失敗？**
請先強制刷新瀏覽器（`Ctrl+F5`）以載入最新 `captcha.js`；若容器被 CSS 壓縮導致舞台寬度不是 300px，系統會自動按比例換算座標。也可在後台「站點配置 → 驗證方式」臨時切到「點選文字」或「推理交換」驗證排查。

**Q8. 點選文字驗證的漢字顯示為方框？**
點選文字依賴 GD 與字體文件渲染。默認使用系統字體，中文顯示不佳時請在 `app/captcha/fonts/` 放置中文字體（如 `SourceHanSansSC-Regular.otf`），系統將自動優先使用。

**Q9. 如何啟用「推理交換」驗證？**
在「站點配置 → 人機驗證」中選擇「推理交換驗證（交換圖塊還原圖片）」，可配置觸發模式（始終/可疑/高風險）與顯示方式（內嵌/彈窗/觸發）。該模式支援簡中/繁中/英文提示。

**Q10. 人機驗證的「觸發模式」在哪裡設置？**
在「站點配置 → 人機驗證」的「顯示方式」下拉中選擇「觸發模式（滑鼠移入輸入框時彈出驗證）」，用戶聚焦輸入框時自動彈出驗證窗口，體驗更友好。

---

> 文檔基於項目源碼（`index.php`、`install.php`、`app/includes/*`、`public/*`）整理，版本 `1.5.0`。
> 如與代碼實現不符，請以代碼與安裝嚮導提示為準。

---

## 其他語言版本

- [简体中文 (README.md)](README.md)
- [English (README.en.md)](README.en.md)
