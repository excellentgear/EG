# EGsystem — AI 工作規範（每次 session 必讀）

> 最後修改：2026-07-31（二次）— 路由表新增 `ai-rules/16-列印文件標準.md`（列印文件三固定元素：大標題＝本公司全名發票用、動態取自 `customer_list.is_own_company=1` 的 `customer_full` **禁寫死**；頁碼「第X頁／共Y頁」統一**左下角**、`@page @bottom-left`＋`counter(pages)` 由列印引擎算、多頁才顯示；綁定的 AS 文件編號統一**頁尾右下角** `@page @bottom-right`、每頁都印）。起因：外來文件清單列印版定案時使用者明確要求全站列印文件比照辦理，而這三件事過去只散在個別頁面（quotation 頁碼、vendor_audit 公司名）沒有成文標準。標準示範頁 `views/Sales/external_doc_list.php`。（同日）鐵律5 擴充**全站統一附件根資料夾**（預設 `\\excellentnas\AS9100維護\ERP AS9100文件(勿刪)`＋以頁面名稱為子資料夾自動建立，唯一實作 `src/common/attach_lib.php` 的 `eg_attach_dir()`／`eg_attach_ensure_dir()`；附件下載一律走 API 不設 URL 前綴）。使用者明確要求，新模組不得再各自寫死 `Z:/BOM/...` 預設路徑。（前次 07-30 五次） UI 規則新增**可增列表格鐵則**（末列↓自動加列、沒填東西的末列↑自動移除，一律走共用檔 `eg_input_rules.js` 的 `data-eg-row-add`/`data-eg-row-del`，禁止各頁自刻）與**推導欄位鐵則**（來源欄位一改就要重算、算不出就清空；「休息時間」一律系統算不給手填）。起因：教育訓練頁把上課時間改成 11:00~12:00 後，休息仍停在班別帶入的 60 分，變成「上課 60 分不足以扣休息 60 分」、時數還留著改之前的舊值——**休息本來就該看課有沒有跨到午休，是不該讓人填的欄位**；使用者同時要求自動增刪列「所有可以增加列的都應該這樣設定」。（同日四次） 路由表新增 `ai-rules/15-圖面變更判定依據.md`（判斷「這張圖是不是改過」一律用**發行章日期**，不是版次）。起因：同一個判定問題連續三版提案被使用者推翻——拿「上傳」「作廢」「版次」當觸發點都不成立，因為客戶圖多半沒版次、舊版不一定作廢、原圖上傳也會作廢且操作者不是設計、原圖更新常常只是報價階段。**這些是問了才知道的現場事實，不寫進 repo 只寫進私人 memory，下一個 AI 會再繞一次同樣的路。**（同日三次）UI 規則新增**人員列表鐵則**（只列未離職／長期請假標記假別與期間／依職稱排序並顯示職稱／跨部門要顯示部門），唯一實作點 `src/common/people_lib.php`，路由表加對應條目。使用者明確要求全站適用。起因：量測儀器校驗的「校驗人員資格」名單用 `user_status<>90` 當在職判定，把離職者也列出來——**在職判定的正確欄位是 `user.state`**，各頁自己拼人員 SQL 遲早寫錯，故收斂成一支共用庫。（同日二次）UI 規則的輸入欄位那條改成**載入共用檔 `resource/js/eg_input_rules.js` 取代各頁手刻**，並加上收尾必跑 `ai-rules/tools/check_input_rules.php`。起因：「有值雙擊清空」等規則早就寫在這裡，但會計 7 頁裡有 4 頁完全沒做、有做的也只綁了跳窗沒綁篩選列——**又是「規則只寫怎麼寫、沒寫怎麼驗收」的同一種失守**（側欄已經教過一次）。共用檔走 document 事件委派，連 AJAX 後才畫出的欄位都涵蓋；既有 76 支頁面列在 `input_rules_baseline.txt` 基準線內不強迫改（硬塞會改變 Enter 行為、可能誤送出，違反鐵律4），新頁面漏載才算不合格。（同日）UI 規則新增「時間（時刻）欄位一律直接輸入、禁用下拉選時間」與「表單三總則（填寫方便性／畫面一致性／錯誤即時偵測並顯示原因）」（使用者明確要求全站適用，完整條文見 `ai-rules/08` 第零節與第二之二節）。起因：教育訓練頁把時間做成 10 分下拉被使用者退回——「對填寫人員很不友善」，且錯誤只在送出時才報。（前次 07-29 四次）路由表新增 `ai-rules/14-職務調動連動檢查.md`（職務調動要留紀錄＋連動點檢表＋前後對照；起因：代理設定因調動被終止後沒人重設，數月後才以「請假頁顯示尚未設定代理人」爆出來）。（同日三次）鐵律6「若新增頁面」加上**收尾必跑 `ai-rules/tools/check_sidebar.php` 側欄健檢**（新工具，掃全站三種側欄失效根因）。原因：規則文字其實已經寫得很清楚，但 purchase_request.php 仍然踩到——因為規則只規範「怎麼寫」、沒規範「怎麼驗收」，而 `php -l` 和搜尋原始碼有無 `sidebar-menu` 字串都驗不出被 CSS 隱藏的側欄。**靠記憶遵守的規則會失守，把它變成一行指令才擋得住。**（同日二次）鐵律6「若新增頁面」再補上**側欄 `visibility:hidden` 必須連恢復 JS 一起抄**（Shipping_Quick.php 五支 JS 都載對了、側欄仍整片消失，因為只抄了 CSS 沒抄 `$('#sidebar-menu').css('visibility','visible')`；這是側欄第三次出事，前兩次是缺 custom.min.js，屬不同根因故並列）。（同日）鐵律6「若新增頁面」補上**版型必備 JS 載入順序**（缺 `custom.min.js` 左側欄選單就死；此陷阱原本只記在 `ai-rules/00-診斷.md`＝壞了才去查的文件，導致新頁面重複踩坑兩次，故移到動工前就會讀到的地方）。（同日）路由表新增 `ai-rules/13-共用帳號通知與綁定.md`（現場共用帳號成員綁定/通知轉送/鎖密碼；規格已定案待實作）。（前次 07-28）路由表新增 `ai-rules/12-請假系統製作說明.md`（請假動工前必讀，代理走 delegate_lib、勿用 leave_agent_setting）；鐵律6 推送改用 **`git pushall`**（雙 remote：origin=excellentgear/EG、backup=ellentravel1003/EGsystem；只 git push 會漏備份）；路由表新增 `ai-rules/11-代理系統設計.md`（代理人解析一律走 delegate_lib）；鐵律5 附件暫存機制（temp/active，見 ai-rules/07）。

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
5. **檔案路徑一律「即時組路徑」＋附件暫存機制＋全站統一根資料夾**：任何跟磁碟/NAS 路徑相關的功能（附件、圖檔、匯出、備份等），DB 只能存檔名／相對值，完整路徑一律在讀取當下用「目前設定值＋即時算出的子資料夾」現場組出；
   - **根路徑一律用共用庫**（使用者明確要求，2026-07-31）：新模組的附件**預設放在 `\\excellentnas\AS9100維護\ERP AS9100文件(勿刪)` 底下，再以「頁面名稱」為子資料夾自動建立**（例：教育訓練頁＝`…\ERP AS9100文件(勿刪)\教育訓練\`）。唯一實作點 `src/common/attach_lib.php`：`eg_attach_dir($db,'模組設定鍵','頁面資料夾')` 取目錄、`eg_attach_ensure_dir($dir)` 確保存在（不存在自動建，含多層）。**不要再各自寫死 `Z:/BOM/...` 預設值**；根路徑要整批搬家時改設定鍵 `eg_attach_root` 一處即可。模組若已在設定鍵填了完整目錄，以該設定優先（既有模組不動、不必回頭改）。
   - 附件下載一律走該模組 API（權限守門＋路徑現場組），**不要**再另外設 URL 前綴讓瀏覽器直連（UNC 路徑瀏覽器也連不到）。**絕對不可**把組好的完整絕對路徑寫死存進 DB 欄位（換 NAS 硬碟或資料夾位置後，舊資料會全部讀不到、且無法只靠改設定修復）。另：**任何有附件的頁面，新增單據時就要能立刻上傳附件，不可要求「先存單據才能上傳」**——用 temp/active 暫存狀態機解決（新增中先存 temp、存檔時轉正、逾期懶惰清除）。兩者的實作標準、可直接抄的範例、尚未合規模組見 `ai-rules/07-附件路徑儲存規範.md`。
6. **收尾四件事**（每完成一個修改立刻做，勿累積到最後、勿等整個任務做完才一次處理）：
   - `php -l` 通過每個改過的檔案
   - `git add <改過的檔案>` → `git commit -m "一句話說明"` → **`git pushall`**（單一檔案改完驗證通過就立刻推，不要累積多檔一起 commit，才能保證每個 commit 都是可回復的獨立檢查點）
     - **推送一律用 `git pushall`，不要只用 `git push`**：本專案設了**兩個 remote**——`origin`＝公帳號 `excellentgear/EG`（共用/目前進度）、`backup`＝私帳號 `ellentravel1003/EGsystem`（備份，防公帳號被他人改動）。`git pushall` 是 alias（`!git push origin master && git push backup master`），一次推兩邊；只 `git push` 會漏掉備份庫。
     - 兩個 remote 的 URL 都已內嵌各自帳號的 PAT。若某邊 push 出現 `Repository not found`(404) 或 401＝該帳號 PAT 失效/無權限：**勿自行改 remote/憑證**，回報使用者重產該帳號 PAT（iOS App 不能產、需 github.com 網頁）。細節與踩坑見記憶 `git_push_broken`。
     - 注意：`views/ADM/db_backup.php` 的「GitHub 帳號綁定」只會覆寫 `origin` 的 token（保留 excellentgear/EG 路徑），不會動 `backup` remote。
   - 寫入 page_change_log（範本見下）
   - 若新增頁面：**頁面底部必須依序載入 `jquery.min.js`→`bootstrap.min.js`→`fastclick.js`→`nprogress.js`→`custom.min.js`（缺 custom.min.js 左側欄選單就死，已重複踩過兩次）；若你的 CSS 抄了 `#sidebar-menu{visibility:hidden;}`，就必須連同 `$(document).ready` 裡那段 `$('#sidebar-menu').css('visibility','visible')` 一起抄（只抄 CSS 沒抄 JS＝側欄整片消失，即使五支 JS 都載對了；照抄 `views/pm/vendor_audit.php` 底部）**；**`.right_col` 的第一個子元素一律加 `clear:both`**（Gentelella 的 `.top_nav` 高度是 0、浮動溢出，第一個子元素若自成 BFC＝`overflow:hidden`/`display:flex`/`float`，會被壓成寬度 0 → 標題整條消失＋上方空一大段；已踩兩次）；**收尾一定要跑側欄健檢：`& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\ai-rules\tools\check_sidebar.php`，三項必須全是「（無）」才算過**（側欄已連四次出事，共三種根因；靠記得遵守擋不住，改用這支工具驗收——`php -l` 與看原始碼有沒有 `sidebar-menu` 字串都抓不到被 CSS 隱藏的情況）；到 `views/user/user_permissions.php` 仿照報價單加上該頁角色設定區塊；並登記進選單＝`system_module_pages` INSERT 一列（page_name＋page_url 格式 `/EGsystem/views/...`、sort_order=MAX+1）再把 group_id 綁到「測試功能」主項目（system_module_groups.group_name='測試功能'），等同 `views/admin/system_module_setting.php` 的「子頁面設定＋主項目綁定」操作；帶參數才能開的子頁（設計器/填寫頁等）不登記，只登記入口頁
7. **每頁必附「使用說明」**（功能面，違反＝本次工作不合格）：任何有實質操作的頁面，一律在**頁首右上角**放一顆「使用說明」按鈕（`class="page-help-btn"`，`@media print` 隱藏），點擊開跳窗（`id="helpUseMask"`），內含**功能說明＋操作步驟＋重要行為/常見疑問＋設定入口＋權限角色**。位置與樣式**全站統一**：照抄 `views/pm/vendor_audit.php` 的 `.page-help-btn`／`.help-doc` CSS 與 `#helpUseMask` modal 結構（按鈕放 `.page-title` 內 `margin-left:auto` 靠右；handler `$('#btnPageHelp').on('click',…openMask('helpUseMask'))`）。改動頁面功能時說明內容要**同步更新**。既有頁面陸續補上。詳見記憶 [[page_usage_help]]。

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
輸入欄位（全部欄位適用）：**新頁面不要自己手刻這些行為，載入共用檔即全頁生效**——`<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>`（放在 `custom.min.js` 之後；它走 document 事件委派，AJAX 之後才畫出來的欄位也涵蓋，不會有「忘記綁某個欄位」的問題）。**收尾必跑驗收：`& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\ai-rules\tools\check_input_rules.php`（兩項全「（無）」才算過）**。共用檔實作的規則：有值雙擊清空（篩選欄雙擊＝同時解除該欄篩選）；聚焦已有資料的欄位自動全選；Enter 跳下一欄、最後一欄按 Enter＝觸發該區塊主要動作鈕（textarea 內 Enter 仍為換行）；多列輸入表格 ↑↓ 鍵切換上下列同欄（日期欄會攔截原生↑↓改日）；數字輸入框無上下增減按鈕、小數尾 0 省略（3.50→3.5、3.00→3）；**可增列表格的「末列↓自動加一列、沒填東西的末列↑自動移除」**。個別欄位要排除加 `data-eg-skip`。頁面自己已 `preventDefault` 的按鍵共用檔不插手，所以可安全與既有處理並存。
**可增列表格鐵則（使用者明確要求，凡是「可以增加列」的表格都適用）**：不要各頁自刻增刪列按鍵邏輯，在該表格 `<tbody>` 掛 `data-eg-row-add="全域函式名" data-eg-row-del="全域函式名"` 即由共用檔接手（含重繪後的游標定位）；「沒填東西」＝該列全空、**或**該列是剛用 ↓ 加出來且使用者一個字都沒動過（所以新列自動帶入上一列的值也不影響）。刪列函式只剩 1 列時要自己擋掉。範例照抄 `views/ADM/training_record.php` 的上課日期明細表，完整說明見 `ai-rules/08` 第一之二節。
**推導欄位鐵則**：由其他欄位算出來的值（時數、金額、休息分鐘…）在來源欄位改變時**一定要重算，算不出來就清空，不可留改之前的舊值**；其中**「休息時間」一律系統算（上課時間 ∩ 休息時段），不給使用者手填**——欄位設 `readonly`＋灰底（`.ro-auto`）＋`data-eg-skip`，後端存檔時再算一次、不採信前端送的值。
日期欄位：一律要能點出月曆選擇，不可只讓使用者手動打字。
時間（時刻）欄位：一律**直接輸入**（text，寬容接受 0900/900/9 並於離開欄位正規化成 HH:MM），**禁用下拉選時間**；即時檢查 0-23/0-59 與「同日結束不可早於開始」。
表單三總則（使用者明確要求，全站適用）：①填寫方便性優先（以現場人員最快填完為準，參照資料仍走下拉且要能當場新增進主檔）②畫面一致性（同種資料全站同元件同格式）③**錯誤即時偵測並顯示原因**（輸入當下就驗，紅框＋該欄旁紅字寫「為什麼錯」，不可只在送出時丟一句「資料有誤」；後端同規則再驗一次）。完整條文見 `ai-rules/08-UI互動規範.md` 第零節與第二之二節。
配色規範（重要，違反＝不合格）：任何上色一律**暖色系**（橘/琥珀/砂/赭/暖棕/珊瑚紅），**禁止冷暖混雜、禁止亂數或 HSL 隨機上色**；分類色用事先定好的固定調色盤（同語意同色、跨頁一致）；有急件燈號語意時一律用固定三色（一般件`#F7E0BD`/急件U`#F0A24B`/特急件E`#DD5138`，`E`最急）；淺底配深棕字、深底配白字，確保對比可讀；顏色不可是唯一資訊，需搭配文字標籤。完整調色盤與檢查清單見 `ai-rules/10-配色與文字可讀性.md`。
**人員列表鐵則（凡是列出「人」的地方都適用，使用者明確要求）：一律呼叫 `src/common/people_lib.php` 的 `eg_people_list($db, $opt)`，禁止各頁自己寫人員 SQL**——①只列未離職者（判定欄位是 **`user.state`**，不是 `user_status`；0離職/90特殊帳號不列）②留職停薪、育嬰留停等長期請假者仍列出但要標記「假別＋請假期間」（`leave_note`）③一律依**職稱**（`position.sort_order`）排序並顯示職稱 ④同一列表會出現不同部門的人時，用 `eg_people_multi_dept()` 判斷並**連部門一起顯示**。完整條文與踩坑見 `ai-rules/08-UI互動規範.md` 第五節。
資料列表：>10 筆分頁（改分頁邏輯前先問使用者）；分頁鈕在列表右上；每頁筆數可選 5/10/20/50；一律有 PDF+CSV 匯出、表頭表尾設定；只預載第 1 頁，其餘頁面/篩選/排序需要時才載入或背景載入。**總計、依運算欄位排序/篩選、匯出等任何要看過全部資料才能算出結果的情況，一律後端對全部符合條件的資料算完才回傳，不可只用前端已載入的那一頁計算**——完整說明與正反例見 `ai-rules/08-UI互動規範.md`。
**全表（全域）搜尋鐵則**：一律 `LIKE '%詞%'` 掃過該單據畫面上看得到的所有欄位（客戶／料號／製程／業務備註／設計備註／設計者／各種單號／容器樣品治具／日期…），多個關鍵字＝每個都要命中（可分散在不同欄位）；**禁止用 ngram FULLTEXT `MATCH…AGAINST` 當全表搜尋**——料號 `RC105-N03-A` 這種含「-」的字串，ngram 把 `-` 當分隔字元，片語比對回傳 0 筆（實測 LIKE 找得到 1 筆、MATCH 0 筆），而萬筆等級的表 LIKE 全掃只要數十毫秒，沒有用 FULLTEXT 的理由。新增可搜尋欄位時兩處（主列表 SQL 與統計 SQL）要同步改，否則筆數與內容對不上。
**工作方式（使用者明確要求）**：以最少 token 完美處理完——先查證再動手、不重複讀檔、批次執行指令、回報只講結論與影響，不貼冗長過程。

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
| 要列出「人員」（勾選名單/下拉/簽核對象/排班/收件人） | `ai-rules/08-UI互動規範.md` 第五節（**必讀**，走 `people_lib.php`） |
| 網頁列印畫質不夠（要接近本機看圖列印） | `ai-rules/09-網頁列印高畫質技巧.md` |
| 要上色（圖表/長條/標籤/狀態色）或改配色 | `ai-rules/10-配色與文字可讀性.md` |
| 做簽核/待辦派送/請假/異常判定，涉及「某人不在由誰代理」 | `ai-rules/11-代理系統設計.md`（**必讀**，禁各頁自寫代理 SQL） |
| 要做/改請假系統（申請、簽核鏈、假別、額度） | `ai-rules/12-請假系統製作說明.md`（**必讀**，代理走 delegate_lib、勿用 leave_agent_setting） |
| 要處理共用帳號（現場多人共用登入）的成員綁定、通知轉送、鎖密碼 | `ai-rules/13-共用帳號通知與綁定.md`（**必讀**，改收件人展開層一處全站生效；改完須回歸測試 ROSTER 通知） |
| 要做/改「員工部門職位異動」及其連帶影響（代理、權限、指定負責人、簽核鏈） | `ai-rules/14-職務調動連動檢查.md`（**必讀**，異動要留紀錄＋連動點檢表＋前後對照；代理不可自動猜人） |
| 要判斷「這張圖是不是改過」（圖面變更、料號附件、檢驗標準換版） | `ai-rules/15-圖面變更判定依據.md`（**必讀**，判準一律用發行章日期；版次／上傳／作廢都不成立，別再重推一次） |
| 要做/改任何**簽核、審核、送審通知** | `ai-rules/17-審核通知標準.md`（**必讀**，通知上要有核准/退回鈕＋退回填原因＋內容完整可看＋附件含類別說明） |
| 要用「品管部門／業務部門／人事部門是哪一個部門」「最高核准人員是誰」等**全站共用的組織綁定** | `src/common/org_role_lib.php`＋設定頁 `views/admin/org_role_setting.php`（**禁止各頁自己寫死部門 id 或人名**） |
| 要做/改任何「列印文件」（報表、表單、清單列印版） | `ai-rules/16-列印文件標準.md`（**必讀**，大標題本公司名動態取禁寫死／**表頭＝已綁定 AS 文件的表單名稱 doc_name，禁寫死**／頁碼左下 counter(pages)／AS 文件編號右下角） |

原始版 CLAUDE.md 備份：`CLAUDE.md.bak-20260706` 與 `CLAUDE(原始 不可更改).md`（勿動；該備份檔名未帶時分屬歷史命名，新備份一律用 `.bak-yyyyMMdd-HHmm`）。
