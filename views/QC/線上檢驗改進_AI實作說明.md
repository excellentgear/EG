# 線上檢驗紀錄（inspection_combined_prototype.php）改進 — AI 實作說明

> 目的：把這份說明交給實作 AI，一次完成 Tier 0～3 全部改進項，並正確支援「同一尺寸多量具／同一尺寸用三次元＋投影機各量一次」。
> 目標檔：`views/QC/inspection_combined_prototype.php`（約 2877 行，線上 DB 版 QC 檢驗，目標是取代人工填的 Excel .xlsm 檢驗表）。

---

## 0. 給實作 AI 的前置守則（務必遵守）

1. **先讀 CLAUDE.md 與 `ai-rules/`**。本專案鐵律摘要：只新增/修 bug 不重構正常功能；DB 寫入用 transaction；巨檔（本檔 >2000 行）**禁止整檔 Read/Write**，先 Grep 定位再 Edit（錨點含前後 2–3 行原文）；每改一個檔 `php -l` 通過→立刻 `git add/commit/push`→寫 `page_change_log`；不確定的欄位名先 `SHOW COLUMNS` 或查 `MYSQL 資料字典.txt`。
2. **執行 SQL**：`& C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\EGsystem\ai-rules\tools\sql.php "SQL"`（中文用 `--file`）。此工具**擋 DDL**；ALTER/CREATE 需用專案 PDO 連線寫一次性 migration 腳本執行，並把新欄位補進 `MYSQL 資料字典.txt`。
3. **不可破壞現有可運作的存檔流程**（`save_inspection` / `update_inspection` 已能正確寫入 `qc_check_form`+`qc_measurement`，經確認可用）。改動一律向後相容。
4. **每個項目獨立 commit**，commit 訊息一句話講清楚。完成後在 `page_change_log` 記一筆（`page_name='views/QC/inspection_combined_prototype.php'`）。
5. **測試紀律**：正式庫測試只刪自己 `lastInsertId` 建立的列，禁止按值刪除/還原（見 `ai-rules`／記憶 testing-discipline）。

## 設計原則（貫穿所有項目）

現場 QC 抗拒系統化、偏好 Excel 的自由。所以兩條紅線：
- **A. 絕不能比 Excel 更綁手**：常見情境（一個尺寸、一支量具、填一個值）必須跟現在一樣快；新增的彈性（多量具、逐列備註、拍照）一律是「可選、預設收合」，不得拖慢單一量測的主流程。
- **B. 給 Excel 給不了的甜頭**：自動帶規格公差、超標自動判、歷史查詢、免檔案鎖定/版本困擾、可印出正式表單。

---

## Tier 0 — 上線前必修（正確性／安全，最高優先）

### #1 RBAC 改為 fail-closed
- **現況**：`loadUserFeatures()`（約第 74 行）在使用者**沒有任何角色**時回傳 `['all']`＝全權（含存檔、改設定）。這是安全漏洞。
- **做法**：無角色 → 回傳**空權限**（`[]`），並讓後端每個寫入型 action（`save_inspection`/`update_inspection`/`update_std`/`set_ncr_decision`/解鎖等）在無對應功能碼時回 `403`＋JSON 錯誤；前端據此禁用按鈕。沿用本專案 RBAC 慣例（見 `RBAC角色權限機制說明.md`、`rf_has_module_role`／功能碼分開設定），唯「管理者」固定全權。
- **驗收**：用一個無角色帳號開頁→不能存檔、不能改設定；管理者→全部可用；一般 QC 角色→只能填檢驗、不能改標準/設定。

### #2 `ensureSchema()` 移出熱路徑
- **現況**：`ensureSchema()`（約 28–60）在幾乎每支 AJAX（155/296/452/519/554/653/674…）跑 `SHOW COLUMNS`＋`ALTER/CREATE`。效能差且要求 web DB 帳號有 ALTER 權限。
- **做法**：把所有 schema 建置/欄位補齊抽成**一次性 migration 腳本**（`views/QC/migrations/` 或 `ai-rules/tools/` 下的 .php，用專案 PDO 執行），正式請求路徑**完全不碰 schema**。migration 內容含本說明所有新欄位（見 #10、#4、#5）。
- **驗收**：移除熱路徑呼叫後，一般存檔/讀取流程正常；用只有 SELECT/INSERT/UPDATE 權限的帳號也能運作。

### #3 後端重新驗算判定＋加 CSRF
- **現況**：後端直接吃前端傳來的 `verdict`/實測 `result`（約 384/416），完全信任瀏覽器；所有 POST 無 CSRF token。品質文件不可如此。
- **做法**：
  - 後端存檔時，對每筆數值型實測值，用該 `item` 的公差（`qc_inspection_item.min_value/max_value/plus_tolerance/minus_tolerance`）**自行重算 OK/NG**，不採信前端的 result；OK/NG 型項目才吃前端。整體 `check_result`、`ng_qty` 後端重算。前端計算仍保留（即時體驗），但**後端為準**。
  - 加 CSRF token（沿用專案既有機制；若無則以 session token 驗證所有寫入型 POST）。
- **驗收**：竄改前端送出的 result 值，存進 DB 的仍是後端依公差算出的正確判定；缺/錯 CSRF token 的 POST 被拒。

---

## Tier 1 — 現場採用關鍵（自由度，別比 Excel 綁手）

### #4 草稿/自動存檔（避免關掉視窗全失）
- **現況**：單次送出；`qc_check_form.status` 已有 `DRAFT` 值可用，但目前沒有草稿流程，關閉 popup 會丟失已填資料。
- **做法**：填寫過程每隔 N 秒／欄位 blur 時，以 `status='DRAFT'` 背景 upsert（同一 `bom_ing_fid`+`batch_no`+`round_no` 一份草稿）；重開頁面自動載回草稿；正式「儲存」才轉 `SUBMITTED`。草稿寫入走既有 transaction 模式。
- **驗收**：填一半關掉視窗、重開→資料還在；正式儲存後草稿轉正式、不重複。

### #5 逐列（單項）備註
- **現況**：`qc_measurement.remark` 欄位已存在，但前端 `collectItems()`（約 2316）從未送出，項目層備註等於無法用。
- **做法**：每個檢驗項目列加一個小備註輸入（可收合/點開，預設不佔空間，符合原則 A），`collectItems()` 收集後存入 `qc_measurement.remark`；讀取時顯示。
- **驗收**：某尺寸旁填「毛邊已修」存檔→重開該筆能看到該列備註。

### #6 本人寬限期可自改（取代「一存就鎖、要主管解」）
- **現況**：存檔後 `edit_unlocked=0` 立即鎖定，要主管解鎖才能改（`update_inspection` 約 553；解鎖約 518）。這是 Excel 自由的反面，傷採用。
- **做法**：加「本人寬限期」——同一 `created_by` 在存檔後一段時間內（例如當班或 N 小時，做成 `system_settings` 可調）可直接修改自己那筆，不需主管解鎖；逾期或他人才需主管解鎖。所有修改仍寫 `qc_inspection_edit_log` 稽核。**不可**因此讓已鎖定/已簽核的正式文件被隨意改。
- **驗收**：本人剛存的紀錄 N 小時內可自改且留稽核；逾期需主管解鎖；他人一律需解鎖。

---

## Tier 2 — 讓它能「取代」Excel 的甜頭（策略主線）

### #7 列印／匯出成熟悉的正式表單版面（頭號優先）
- **現況**：全頁沒有任何列印或 Excel/紙本版面匯出。管理/客戶/AS9100 稽核仍需要「看起來像正式檢驗表」的文件，沒有這個就無法真正取代 Excel。
- **做法**（擇一或並行）：
  - **列印版面**：做一個列印專用檢視（print CSS，A4，欄位對齊既有紙本 2-QA-01-06 版面），把 header＋逐項實測＋判定＋簽核區排成正式表單，`window.print()`。**列印分頁務必遵守專案鐵則**：禁止 JS 量高度自算分頁，一律單一表格交瀏覽器原生分頁（見記憶 print-pagination／`ai-rules/09`）。
  - **Excel 匯出**：若要 .xlsm 版面，可接既有 `src/common/qc_form_generator.php` 管線把線上資料回填到範本分頁（謹慎，成本較高，可列為 #7 第二階段）。
- **驗收**：填完一筆→列印/匯出得到與現行紙本相近、可交稽核的表單；多列自動正確分頁。

### #8 歷史查詢接上（目前是假的）
- **現況**：右上「歷史紀錄」只 `alert('…正式版會接上')`（約 2873）。`load_context` 只帶同項目的批次歷史（約 2003）。
- **做法**：做「同料號歷次檢驗」跨 BOM 查詢——依 `d_id`（料號）列出歷來 `qc_check_form`（日期/製令/製程/整體判定/不良數/檢驗人），點入可看該筆逐項實測值；提供依項目看「同尺寸歷來實測趨勢」。這是 Excel 給不了的甜頭。
- **驗收**：驗某料號時能秒查上次同料號怎麼驗、關鍵尺寸歷來落點。

---

## Tier 3 — 打磨

### #9 平板友善
- **現況**：固定像素格（`.s-slot` 70px，約 917/920）、每 PCS 多個小輸入框、鍵盤格狀導航（約 2228–2264）偏桌機。
- **做法**：改相對單位＋加大觸控目標；提供「一次填一個 PCS／一個項目」的單欄模式供平板使用；量測輸入支援數字鍵盤（`inputmode="decimal"`）。不得破壞桌機既有格狀輸入效率。
- **驗收**：平板上單手可順利填寫、按鈕好按、不需放大。

### #10 量具追溯 ＋【同尺寸多量具／多次量測】← 使用者特別要求，詳見下方專章
見 **§多量具與多次量測資料模型**。

### #11 抽樣（可選）
- 現況為 `qc_sampling_rule` 數量帶查表（約 219–228），非正規 AQL/ANSI Z1.4。若客戶要求正規 AQL 才實作抽樣水準/允收數表；否則維持現況。

### #12 程式碼整理
- `save_inspection`（295–446）與 `update_inspection`（553–647）的項目解析/寫入邏輯幾乎重複 → 抽共用函式（存檔與更新共用同一套「解析 items → 後端重算判定 → 寫 measurement」）。
- 判定同時存 header 的 `pcs_verdicts` JSON 與逐列 `item_verdict`（`qc_measurement`）→ 擇一為單一真相來源（建議以 `qc_measurement` 明細為準，header 只存彙總 `check_result`/`ng_qty`），避免不一致。

---

## §多量具與多次量測 資料模型（#10 核心，務必照此設計）

### 需求
1. **同一尺寸可用多支量具**檢測（例如外徑用卡尺，也可用測微器）。
2. **同一尺寸可能同時用「三次元」量一次、又用「投影機」量一次**——也就是**同一項目、同一 PCS，可以有多筆量測讀值**，每筆各有自己的量測方法、量具、實測值與 OK/NG。
3. **量具可追溯**：每筆讀值要記到「實際使用的那支量具實例」（含量具編號）。

### 現有結構（已查證，可沿用）
- `qc_measurement`：`qc_form_id, item_id, sample_no, measured_value, result(OK/NG), tool_id(→qc_tool.Tool_id 實際量具實例), remark, item_verdict`。目前**每 (form,item,sample) 只一列、一個 tool_id**。
- `qc_tool`：`Tool_id(PK), Tool_No(量具編號=可追溯), QC_Tool_List_id(量具類型)`。
- `qc_inspection_item_tool_type`：`item_id → QC_Tool_List_id, is_primary`——**一個檢驗項目本來就能掛多個量具類型**（多量具已有結構基礎）。

### 要做的改動

**(a) 放寬量測顆粒度：允許 (form,item,sample) 多列**
在 `qc_measurement` 新增：
- `measure_method varchar(20) NULL`：量測方法（例：`三次元`／`投影機`／`手動`／`其他`；可做成 `system_settings` 或小字典維護的下拉，允許自訂）。
- `reading_seq tinyint NOT NULL DEFAULT 1`：同 (item,sample,method) 需量超過一次時的序號（罕見，但保留）。
- `tool_id` 已存在，續用為「該筆讀值實際使用的量具實例」。
> 顆粒度變為 **(qc_form_id, item_id, sample_no, measure_method, tool_id, reading_seq)**。不加唯一鍵硬約束（存檔採「刪除該 form 全部 measurement 再整批重寫」即可，維持既有交易模式），但同批內以此組合去重避免重複列。

**(b) 判定彙總規則（多讀值時）**
- **項目在該 PCS 的判定**：該 (item,sample) 底下**所有讀值全部 OK 才 OK；任一 NG ⇒ 該項 NG**（例：三次元過但投影機不過 ⇒ 該尺寸 NG，需處置/特採）。允許主管手動改判 `AOD`（特採），沿用既有覆寫機制。
- **PCS 判定**、**整體 check_result/ng_qty**：沿用現有「任一項 NG ⇒ PCS NG」「有 NG PCS 計不良數」，但**改成掃描每項的多讀值彙總後**再 rollup。**後端重算（見 #3）**。

**(c) UI（嚴守原則 A：常見單讀值不能變慢）**
- 每個檢驗項目列**預設就是一個讀值**（一個方法＋一個量具＋一個值），跟現在一樣快。
- 該列提供「**＋ 加量測**」小按鈕，點了才展開「再一筆讀值」子列：欄位＝[量測方法下拉][量具下拉][實測值][自動 OK/NG]。
- **量具下拉來源**：該 `item` 於 `qc_inspection_item_tool_type` 允許的量具類型 → 對應 `qc_tool` 的實際量具實例（顯示 `Tool_No`）。`is_primary` 者預設選取。
- 多讀值時，該項目列顯示彙總判定（全 OK 才 OK）。

**(d) 量具追溯（校期，選作/第二階段）**
- `qc_tool` 目前無校驗到期欄位。若 AS9100 需要校期管控：新增 `calibration_due date NULL` 至 `qc_tool`（migration），選到**逾校期**量具時前端警示、後端可設定為擋下或僅提示。此為 #10 第二階段，需搭配量具主檔維護，先確認再做。

### 存檔/讀取調整重點
- `collectItems()`（約 2316）：由「每項一值」改成「每項一組讀值陣列」，每筆含 method/tool_id/value(/remark)。
- `save_inspection`/`update_inspection`（與 #12 抽出的共用函式）：對每項的讀值陣列逐筆寫 `qc_measurement`（含 measure_method/reading_seq/tool_id），後端逐筆重算 result，再彙總項目判定與整體結果。
- 讀取（`load_context`／載入既有紀錄）：把同 (item,sample) 的多筆讀值組回前端該項目的讀值陣列。

### 驗收（多量具/多次量測）
1. 單一尺寸、單量具、填一個值 → 操作步數與現在相同（沒變慢）。
2. 某外徑項目點「＋加量測」，分別用「三次元」與「投影機」各填一個值、各選不同量具 → 存檔後 `qc_measurement` 有 2 筆（同 item_id/sample_no、不同 measure_method/tool_id），各自 OK/NG 正確。
3. 三次元 OK、投影機 NG → 該項目該 PCS 彙總為 NG、整體不良數正確、可主管特採改判並留稽核。
4. 每筆讀值都查得到實際量具 `Tool_No`（可追溯）。
5. 重開該筆 → 多讀值正確載回、可再編輯。

---

## 建議實作順序
1. **#2 抽 migration**（先把所有新欄位一次補上：measure_method/reading_seq、逐列備註沿用 remark、草稿/寬限期所需欄位、選作 calibration_due），順便讓熱路徑不再動 schema。
2. **#1 RBAC fail-closed** →  **#3 後端重算＋CSRF**（安全，不修不能上線）。
3. **#10 多量具/多次量測資料模型**（改動 collectItems + 存/讀 + 判定彙總；與 #12 共用函式一起做最連貫）。
4. **#4 草稿/autosave**、**#5 逐列備註**、**#6 本人寬限期**（採用度）。
5. **#7 列印/匯出**（取代 Excel 主線）→ **#8 歷史查詢**（甜頭）。
6. **#9 平板**、**#11 AQL(可選)**、**#12 收尾整理**。

每步：`php -l`→commit/push→`page_change_log`→（有 schema 變更）補 `MYSQL 資料字典.txt`。
