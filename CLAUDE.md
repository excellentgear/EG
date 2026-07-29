# EGsystem — AI 工作規範（每次 session 必讀）

> 最後修改：2026-07-29 — 鐵律6「若新增頁面」補上**版型必備 JS 載入順序**（缺 `custom.min.js` 左側欄選單就死；此陷阱原本只記在 `ai-rules/00-診斷.md`＝壞了才去查的文件，導致新頁面重複踩坑兩次，故移到動工前就會讀到的地方）。（同日）路由表新增 `ai-rules/13-共用帳號通知與綁定.md`（現場共用帳號成員綁定/通知轉送/鎖密碼；規格已定案待實作）。（前次 07-28）路由表新增 `ai-rules/12-請假系統製作說明.md`（請假動工前必讀，代理走 delegate_lib、勿用 leave_agent_setting）；鐵律6 推送改用 **`git pushall`**（雙 remote：origin=excellentgear/EG、backup=ellentravel1003/EGsystem；只 git push 會漏備份）；路由表新增 `ai-rules/11-代理系統設計.md`（代理人解析一律走 delegate_lib）；鐵律5 附件暫存機制（temp/active，見 ai-rules/07）。

PHP + MySQL 內網 ERP（倉庫管理），MAMP 本地執行，Windows 10。**已用 git 版本控管**，分支 `master`，改壞可用 git 復原——但前提是每個檔案改完都有立刻 commit＋**`git pushall`**（雙 remote，見鐵律6），沒 push 的部分一樣救不回來。

## 環境速查
- 網址 http://192.168.2.128/EGsystem ｜ PHP 8.3.1 ｜ MySQL 9.4.0（utf8mb4）｜ Apache 2.4.33 ｜ phpMyAdmin 5.2.3
- 資料庫名 `EGsystem`；資料字典：`MYSQL 資料字典.txt`（196KB，用 Grep 查，勿整讀）
- **本機同時裝了 3 套 MySQL/MariaDB**：EGsystem 固定連 **port 3306**（官方 MySQL 9.4 服務 `MySQL94`，datadir=`C:\ProgramData\MySQL\MySQL Server 9.4\Data\`）；port 3307=MAMP 內建、3308=MariaDB 11.8，皆與 EGsystem 無關。系統層級操作 DB（非透過 sql.php）務必指定 3306，別接錯，詳見 `ai-rules/00-診斷.md` 陷阱表。
- 執行 SQL 唯一正道：`& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\ai-rules\tools\sql.php "SQL語句"`（中文 SQL 改用 `--file`；一次一句）。**勿用 mysql.exe**（舊 client 連不上 MySQL 9.4）。
- PHP 語法檢查：`& C:\MAMP\bin\php\php8.3.1\php.exe -l 檔案路徑`

## 鐵律（違反任一條 = 本次工作不合格）
1. **改檔前確認可回復**：本專案已用 git 版本控管，**不再手動 Copy-Item 備份 .bak 檔**（舊 .bak-* 已加入 .gitignore，不進版控）。改檔前用 `git status` 確認上一版已 commit 乾淨；真正的保障來自鐵律6——改完立刻 commit+push，才有真正救得回來的版本。
2. **巨檔保護**：本專案多個 view 超過 500KB（stock.php 721KB、master_data_management.php 1.5MB）。超過 2000 行的檔案：禁止整檔 Read（先 Grep 定位，再 Read offset/limit ±100 行）；禁止 Write 整檔覆寫（只能 Edit，錨點含前後 2–3 行原文）。
3. **不猜，但問要有效率**：不確定的需求、業務邏輯、UI 取捨 → 問使用者；不確定的欄位名 → 先 `SHOW COLUMNS` 或查資料字典。**問的方式（使用者明確要求）**：小事用合理預設自行決定、做完一次回報；真正要使用者拍板的問題**先收集齊、一次問完**（AskUserQuestion 多題並列），不要一問一答打斷使用者；工具/指令盡量批次執行，減少授權次數。何時該問、何時自己判斷：見 `ai-rules/02-判斷力rubric.md`。
4. **不破壞既有功能**：只新增或修 bug，不重構已正常運作的程式。DB 寫入用 transaction。
5. **檔案路徑一律「即時組路徑」＋附件暫存機制**：任何跟磁碟/NAS 路徑相關的功能（附件、圖檔、匯出、備份等），DB 只能存檔名／相對值，完整路徑一律在讀取當下用「目前設定值＋即時算出的子資料夾」現場組出；**絕對不可**把組好的完整絕對路徑寫死存進 DB 欄位（換 NAS 硬碟或資料夾位置後，舊資料會全部讀不到、且無法只靠改設定修復）。另：**任何有附件的頁面，新增單據時就要能立刻上傳附件，不可要求「先存單據才能上傳」**——用 temp/active 暫存狀態機解決（新增中先存 temp、存檔時轉正、逾期懶惰清除）。兩者的實作標準、可直接抄的範例、尚未合規模組見 `ai-rules/07-附件路徑儲存規範.md`。
6. **收尾四件事**（每完成一個修改立刻做，勿累積到最後、勿等整個任務做完才一次處理）：
   - `php -l` 通過每個改過的檔案
   - `git add <改過的檔案>` → `git commit -m "一句話說明"` → **`git pushall`**（單一檔案改完驗證通過就立刻推，不要累積多檔一起 commit，才能保證每個 commit 都是可回復的獨立檢查點）
     - **推送一律用 `git pushall`，不要只用 `git push`**：本專案設了**兩個 remote**——`origin`＝公帳號 `excellentgear/EG`（共用/目前進度）、`backup`＝私帳號 `ellentravel1003/EGsystem`（備份，防公帳號被他人改動）。`git pushall` 是 alias（`!git push origin master && git push backup master`），一次推兩邊；只 `git push` 會漏掉備份庫。
     - 兩個 remote 的 URL 都已內嵌各自帳號的 PAT。若某邊 push 出現 `Repository not found`(404) 或 401＝該帳號 PAT 失效/無權限：**勿自行改 remote/憑證**，回報使用者重產該帳號 PAT（iOS App 不能產、需 github.com 網頁）。細節與踩坑見記憶 `git_push_broken`。
     - 注意：`views/ADM/db_backup.php` 的「GitHub 帳號綁定」只會覆寫 `origin` 的 token（保留 excellentgear/EG 路徑），不會動 `backup` remote。
   - 寫入 page_change_log（範本見下）
   - 若新增頁面：**頁面底部必須依序載入 `jquery.min.js`→`bootstrap.min.js`→`fastclick.js`→`nprogress.js`→`custom.min.js`（缺 custom.min.js 左側欄選單就死，已重複踩過兩次）**；到 `views/user/user_permissions.php` 仿照報價單加上該頁角色設定區塊；並登記進選單＝`system_module_pages` INSERT 一列（page_name＋page_url 格式 `/EGsystem/views/...`、sort_order=MAX+1）再把 group_id 綁到「測試功能」主項目（system_module_groups.group_name='測試功能'），等同 `views/admin/system_module_setting.php` 的「子頁面設定＋主項目綁定」操作；帶參數才能開的子頁（設計器/填寫頁等）不登記，只登記入口頁

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
輸入欄位（全部欄位適用）：有值雙擊清空（篩選欄雙擊＝同時解除該欄篩選）；聚焦已有資料的欄位自動全選；Enter 跳下一欄、最後一欄按 Enter＝送出存檔（textarea 內 Enter 仍為換行）；多列輸入表格 ↑↓ 鍵切換上下列同欄（有新增列按鈕的表格：末列↓自動加列、↑離開全空列自動移除；日期欄攔截原生↑↓改日）。數字輸入框另加：無上下增減按鈕；小數尾 0 省略（3.50→3.5、3.00→3）。
日期欄位：一律要能點出月曆選擇，不可只讓使用者手動打字。
配色規範（重要，違反＝不合格）：任何上色一律**暖色系**（橘/琥珀/砂/赭/暖棕/珊瑚紅），**禁止冷暖混雜、禁止亂數或 HSL 隨機上色**；分類色用事先定好的固定調色盤（同語意同色、跨頁一致）；有急件燈號語意時一律用固定三色（一般件`#F7E0BD`/急件U`#F0A24B`/特急件E`#DD5138`，`E`最急）；淺底配深棕字、深底配白字，確保對比可讀；顏色不可是唯一資訊，需搭配文字標籤。完整調色盤與檢查清單見 `ai-rules/10-配色與文字可讀性.md`。
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
| 網頁列印畫質不夠（要接近本機看圖列印） | `ai-rules/09-網頁列印高畫質技巧.md` |
| 要上色（圖表/長條/標籤/狀態色）或改配色 | `ai-rules/10-配色與文字可讀性.md` |
| 做簽核/待辦派送/請假/異常判定，涉及「某人不在由誰代理」 | `ai-rules/11-代理系統設計.md`（**必讀**，禁各頁自寫代理 SQL） |
| 要做/改請假系統（申請、簽核鏈、假別、額度） | `ai-rules/12-請假系統製作說明.md`（**必讀**，代理走 delegate_lib、勿用 leave_agent_setting） |
| 要處理共用帳號（現場多人共用登入）的成員綁定、通知轉送、鎖密碼 | `ai-rules/13-共用帳號通知與綁定.md`（**必讀**，改收件人展開層一處全站生效；改完須回歸測試 ROSTER 通知） |

原始版 CLAUDE.md 備份：`CLAUDE.md.bak-20260706` 與 `CLAUDE(原始 不可更改).md`（勿動；該備份檔名未帶時分屬歷史命名，新備份一律用 `.bak-yyyyMMdd-HHmm`）。
