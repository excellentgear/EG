# EGsystem — AI 工作規範（每次 session 必讀）

> 最後修改：2026-07-15 — 鐵律3 加入「小事用預設自行決定、問題收集一次問、批次執行減少授權」（使用者明確要求）。

PHP + MySQL 內網 ERP（倉庫管理），MAMP 本地執行，Windows 10。**已用 git 版本控管**（GitHub private repo `ellentravel1003/EGsystem`，分支 `master`），改壞可用 git 復原——但前提是每個檔案改完都有立刻 commit+push（見鐵律6），沒 push 的部分一樣救不回來。

## 環境速查
- 網址 http://192.168.2.128/EGsystem ｜ PHP 8.3.1 ｜ MySQL 9.4.0（utf8mb4）｜ Apache 2.4.33 ｜ phpMyAdmin 5.2.3
- 資料庫名 `EGsystem`；資料字典：`MYSQL 資料字典.txt`（196KB，用 Grep 查，勿整讀）
- 執行 SQL 唯一正道：`& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\ai-rules\tools\sql.php "SQL語句"`（中文 SQL 改用 `--file`；一次一句）。**勿用 mysql.exe**（舊 client 連不上 MySQL 9.4）。
- PHP 語法檢查：`& C:\MAMP\bin\php\php8.3.1\php.exe -l 檔案路徑`

## 鐵律（違反任一條 = 本次工作不合格）
1. **改檔前確認可回復**：本專案已用 git 版本控管，**不再手動 Copy-Item 備份 .bak 檔**（舊 .bak-* 已加入 .gitignore，不進版控）。改檔前用 `git status` 確認上一版已 commit 乾淨；真正的保障來自鐵律6——改完立刻 commit+push，才有真正救得回來的版本。
2. **巨檔保護**：本專案多個 view 超過 500KB（stock.php 721KB、master_data_management.php 1.5MB）。超過 2000 行的檔案：禁止整檔 Read（先 Grep 定位，再 Read offset/limit ±100 行）；禁止 Write 整檔覆寫（只能 Edit，錨點含前後 2–3 行原文）。
3. **不猜，但問要有效率**：不確定的需求、業務邏輯、UI 取捨 → 問使用者；不確定的欄位名 → 先 `SHOW COLUMNS` 或查資料字典。**問的方式（使用者明確要求）**：小事用合理預設自行決定、做完一次回報；真正要使用者拍板的問題**先收集齊、一次問完**（AskUserQuestion 多題並列），不要一問一答打斷使用者；工具/指令盡量批次執行，減少授權次數。何時該問、何時自己判斷：見 `ai-rules/02-判斷力rubric.md`。
4. **不破壞既有功能**：只新增或修 bug，不重構已正常運作的程式。DB 寫入用 transaction。
5. **檔案路徑一律「即時組路徑」**：任何跟磁碟/NAS 路徑相關的功能（附件、圖檔、匯出、備份等），DB 只能存檔名／相對值，完整路徑一律在讀取當下用「目前設定值＋即時算出的子資料夾」現場組出；**不可**把組好的完整絕對路徑寫死存進 DB 欄位——否則換 NAS 硬碟或資料夾位置後，舊資料會全部讀不到。範例、檢查清單、目前尚未合規模組見 `ai-rules/07-附件路徑儲存規範.md`。
6. **收尾四件事**（每完成一個修改立刻做，勿累積到最後、勿等整個任務做完才一次處理）：
   - `php -l` 通過每個改過的檔案
   - `git add <改過的檔案>` → `git commit -m "一句話說明"` → `git push`（單一檔案改完驗證通過就立刻推，不要累積多檔一起 commit，才能保證每個 commit 都是可回復的獨立檢查點）
   - 寫入 page_change_log（範本見下）
   - 若新增頁面：到 `views/user/user_permissions.php` 仿照報價單加上該頁角色設定區塊

## page_change_log 寫入範本
```
& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\ai-rules\tools\sql.php --file 暫存sql檔絕對路徑
```
暫存 SQL 檔內容（用 Write 工具建立，確保 UTF-8）：
```sql
INSERT INTO page_change_log (page_name, summary, detail, changed_at, created_by)
VALUES ('views/xx/xxx.php', '一句話說明', '詳細說明可分點', NOW(), 'Claude');
```
完成後在回覆中告知已寫入哪一筆。

## UI 規則（所有頁面一體適用）
輸入欄位：有值時滑鼠左鍵雙擊清空內容（篩選欄雙擊＝清空並解除該欄篩選，其他欄位篩選不受影響）。數字輸入框另加：無上下增減按鈕；小數尾 0 省略（3.50→3.5、3.00→3）；Enter 跳下一欄；聚焦時全選內容。
日期欄位：一律要能點出月曆選擇，不可只讓使用者手動打字。
資料列表：>10 筆分頁（改分頁邏輯前先問使用者）；分頁鈕在列表右上；每頁筆數可選 5/10/20/50；一律有 PDF+CSV 匯出、表頭表尾設定；只預載第 1 頁，其餘頁面/篩選/排序需要時才載入或背景載入。**總計、依運算欄位排序/篩選、匯出等任何要看過全部資料才能算出結果的情況，一律後端對全部符合條件的資料算完才回傳，不可只用前端已載入的那一頁計算**——完整說明與正反例見 `ai-rules/08-UI互動規範.md`。

## 權限（RBAC）
- 細節見 `RBAC角色權限機制說明.md`。每頁的新增/修改/刪除/檢閱角色**分開設定**；唯「管理者」固定擁有全部權限。
- 頁面標頭右側顯示目前使用者角色 + `?` 圖示（點擊出現各角色權限說明跳窗）。

## 制度檔案路由（ai-rules/）
| 情境 | 讀這份 |
|---|---|
| 想知道規則背後原因、環境陷阱表 | `ai-rules/00-診斷.md` |
| 要派 subagent、選模型、任務失敗要不要升級 | `ai-rules/01-模型調度守則.md` |
| 拿不定主意（該問嗎/算完成嗎/方向錯了嗎） | `ai-rules/02-判斷力rubric.md` |
| 要寫派工 prompt（搜尋/實作/重構/研究/審查） | `ai-rules/03-派工模板.md` |
| 要修改 ai-rules 或 CLAUDE.md 本身 | `ai-rules/04-維護協議.md` |
| session 開始想快速接手景況 | `ai-rules/05-給未來session的信.md` |
| 使用者要在 claude.ai App 沿用這套工作方法 | `ai-rules/06-ClaudeApp執行判斷邏輯.md`（貼入 Project Instructions 用） |
| 要新增/修改任何「檔案路徑存 DB」相關功能（附件、圖檔、NAS） | `ai-rules/07-附件路徑儲存規範.md` |
| 要做輸入欄位／日期欄位／資料列表相關頁面 | `ai-rules/08-UI互動規範.md` |

原始版 CLAUDE.md 備份：`CLAUDE.md.bak-20260706` 與 `CLAUDE(原始 不可更改).md`（勿動；該備份檔名未帶時分屬歷史命名，新備份一律用 `.bak-yyyyMMdd-HHmm`）。
