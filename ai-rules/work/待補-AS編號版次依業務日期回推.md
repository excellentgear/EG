# 待補：供應商稽核／教育訓練模組的 AS 編號版次要依業務日期回推

> 給接手這個任務的 AI：這是一份**一次性工作說明**，不是永久規則（永久規則在 `ai-rules/16-列印文件標準.md` 第三之四節，
> 已經寫好了，你不用也不要重寫）。你的工作是把下面兩個模組串接上第三之四節已經定案的機制。
> **做完、驗證過、且已依鐵律6收尾（commit+push+page_change_log）之後，把這份檔案本身刪掉**（`git rm` 並 commit），
> 不要留著——這只是任務交接用，不是要長期保留的文件，免得日後有人以為這仍是「待辦」而重複做或誤判進度。

## 背景

2026-08-06 使用者發現：列印綁定 AS 文件的舊單據時，編號後面附加的版次一律印「現在最新版」（`as_document.current_version`），
跟被印單據自己的業務日期無關——正確行為應該是依 `as_document_version.revised_date`（改版生效日）回推「這筆單據業務日期
當時生效的版次」。規則與範例見 `ai-rules/16-列印文件標準.md` 第三之四節，**先讀那一節再開始**。

當次已完成並上線的機制（`src/common/asdoc_lib.php`）：

```php
// 回推版次字串；null＝該文件完全無版本履歷（理論上不會發生，165份AS文件已全部補建），呼叫端應退回 current_version
eg_asdoc_version_asof(PDO $db, int $docId, ?string $bizDate): ?string

// 依 as_document.id 直接組出「該業務日期」應印出的完整編號（含四階版次附加判斷）
eg_asdoc_no_asof_id(PDO $db, int $docId, ?string $bizDate = null): string

// 依模組代碼（system_parameters AS_DOC_BIND 群組存的那種綁定）＋業務日期組出編號
eg_asdoc_no_asof(PDO $db, string $module, ?string $bizDate = null): string
```

`$bizDate` 傳 `null`／空字串＝視為今天（等同印「現在最新版」，跟舊行為一樣）。

已完成串接可以直接抄的參考實作：**`src/store/Meeting_API.php` 的 `get_detail` action**（讀出單一筆會議記錄時，
用該筆的 `meeting_date` 算出 `as_doc_record_no`/`as_doc_signsheet_no` 一起塞進回傳的 `$m` 陣列，前端
`views/ADM/meeting_record.php` 直接讀 `m.as_doc_record_no`，不再讀全域 `META.as_doc_record_no`）。
其他已完成：`src/store/Quotation_API.php`（按 `quote_date`）、`views/QC/inspection_entry_v2.php` 單製程列印
（按 `check_date`，前端 `loadPrintCfg(cb, bizDate)` 列印前先重取一次）、`views/QC/inspection_print_multi.php`
多製程合印（按合印範圍內最新一筆 `check_date`）。

## 已經幫你鋪好路的部分

這兩支函式**已經加上可選的 `$bizDate` 參數，簽名已改好、向下相容（不傳＝舊行為不變）**，你不用再改函式本身，
只要在正確的呼叫端傳對日期：

- `src/common/vendor_audit_lib.php` 的 `vendor_audit_bound_asdoc(PDO $db, string $key = 'vendor_audit_as_doc_id', ?string $bizDate = null): ?array`
- `src/common/training_lib.php` 的 `training_as_doc_no(PDO $db, string $which, ?string $bizDate = null): string`

## 任務一：供應商稽核（`views/pm/vendor_audit.php` + `src/store/VendorAudit_API.php`）

`vendor_audit_bound_asdoc()` 有 5 個 key，對應 5 張表單。**先去讀 `views/pm/vendor_audit.php` 搞清楚每張表單印的
是「單一筆有自己業務日期的紀錄」還是「多筆彙總的清單/計畫」——只有前者要串日期，後者維持印現在最新版（不要改）：**

| key（`vendor_eval_setting` 設定鍵） | 文件編號 | 前端變數/函式線索（已用 Grep 找到，行號僅供參考，實際位置以檔案為準） | 判斷方向（需你確認） |
|---|---|---|---|
| `vendor_audit_as_doc_id`（預設 key） | 2-PH-01-02 | `META.as_doc`，`docNo1` 用在約 1678 行附近 | 疑似「供應商評鑑稽核表」單筆稽核紀錄，可能要串**該次稽核日期** |
| `vendor_record_as_doc_id` | 2-PH-01-03 | `META.record_as_doc`，`recordSheetHTML()`（約 1486、1560、1587 行） | 疑似單筆「供應商品質系統評鑑記錄表」，可能要串**該次評鑑日期** |
| `vendor_eval_as_doc_id` | 2-PH-01-05 | `META.eval_as_doc`（約 1860 行） | 疑似單筆「供應商定期評核表」，可能要串**該次評核日期** |
| `vendor_roster_as_doc_id` | 2-PH-01-04 | `META.roster_as_doc`（約 2064 行） | 疑似「合格供應商清冊」＝多筆彙總清單，**很可能不用改**（印現況才對） |
| `vendor_plan_as_doc_id` | 2-PH-01-06 | `PLANDATA.plan_as_doc`（約 2181-2182 行） | 疑似「供應商稽核計劃」＝整年度計畫清單，**很可能不用改**（印現況才對），但也可能有計畫本身的擬定/核定日期，需你實際看資料表結構判斷 |

`VendorAudit_API.php` 目前這 5 個都在類似 `meta`（load 一次、不綁特定紀錄）的 action 裡回傳（約 79-83、812-816、835 行），
跟 Meeting 改之前一樣的架構問題——單一筆紀錄的日期只有在該筆紀錄真正被讀出來的那個 action 才知道，你要照 Meeting 的
`get_detail` 模式，找到（或新增）「讀單一筆稽核/評核紀錄」的 action，在那裡用該筆紀錄自己的日期欄位算出 `doc_no` 一起
回傳，前端該印表函式改讀這個逐筆算好的值，而不是 `META.xxx_as_doc.doc_no`。

## 任務二：教育訓練（`views/ADM/training_record.php` + `src/store/Training_API.php` + 相關 lib）

`training_as_doc_no($db, $which)` 有 5 個 `$which`：`plan`／`result`／`target`／`request`／`signsheet`。已知線索：

| which | 文件用途 | 已知呼叫端 | 判斷方向（需你確認） |
|---|---|---|---|
| `plan` | 2-MM-01-?? 年度教育訓練計劃表 | `views/ADM/training_plan_approval_view.php:33`（`$year` 整年度，`training_session WHERE year=?`） | 已確認是**整年度清單**（多筆彙總），**不用改**，印現況即可 |
| `request` | 2-MM-01-05 教育訓練需求申請單 | `views/ADM/training_request_approval_view.php:43`（`training_request WHERE request_id=?`，單筆） | 是**單一筆**申請紀錄，要串該筆的業務日期——先確認 `training_request` 表有沒有申請日期欄位，沒有就用 `created_at`（比照 ai-rules/16 第三之四節「單據無日期欄位用建立日期」） |
| `result` | 訓練成果/簽到相關表 | 見 `Training_API.php:115-117,181-183`（跟 `plan`/`target`/`request`/`signsheet` 一起在同一個 action 回傳，疑似也是 meta 型態） | 需你追查這個 action 服務的是哪個畫面、是不是也綁單一筆 `training_session` | 
| `target` | 同上 | 同上 | 同上，需追查 |
| `signsheet` | 教育訓練簽到表 | 同上 | 若印的是單一場次的簽到表，應比照 `meeting_record` 簽到表模式，串該場次的**開課日期**（`training_session` 應該有課程日期欄位，先 `SHOW COLUMNS` 或查資料字典確認實際欄名） |

`Training_API.php:115-117` 與 `181-183` 這兩處目前都是 meta 型態（一次算好 5 個 doc_no，不綁特定場次/紀錄），
跟 Meeting 改之前一樣——你要找到（或新增）「讀單一筆 training_session／training_request」的 action，在那裡才知道
正確的業務日期，逐筆算好再回傳，不要在 meta 裡就把 doc_no 定死。

## 動工前必查

- 先用 `SHOW COLUMNS FROM training_session` / `SHOW COLUMNS FROM training_request` / 供應商稽核相關資料表（表名需你自己在
  `vendor_audit_lib.php` 裡找 SQL 反查），確認實際的日期欄位名稱，不要用猜的（鐵律3「不猜，但問要有效率」）。
- 不確定某張表單到底是「單一筆有業務日期」還是「多筆彙總清單」時，直接把畫面/資料流程看清楚（照上面表格的判斷方向去查證），
  不確定就問使用者，不要自己猜了就動手改（避免像本節這樣事後才發現是彙總清單）。

## 驗證方式（照抄本次驗證用的手法）

改完之後，用一支小測試腳本直接呼叫 `eg_asdoc_version_asof()`／改過的函式，帶入該文件在 `as_document_version` 表裡
「改版生效日前一天」「改版生效日當天」兩組日期，確認版次剛好在生效日當天切換，例如：

```php
require_once 'src/common/_config.php';
require_once 'src/common/DBConnection.php';
require_once 'src/common/vendor_audit_lib.php'; // 或 training_lib.php
$db = (new DBConnection())->getPDO();
// 查該模組實際綁定的 as_document.id 是哪一筆，再對 as_document_version 抓出它的改版生效日清單，
// 挑生效日前一天／當天各測一次，確認版次切換點正確。
```

腳本用完刪掉（比照鐵律1，不留暫存檔進版控），或存到 scratchpad 不要放進 repo。

## 收尾（一件都不能少，鐵律6）

1. 每個改過的檔案 `php -l` 通過。
2. 逐檔 `git add` → `git commit`（訊息說清楚改了什麼）→ **`git pushall`**（雙 remote）。
3. 寫入 `page_change_log`（範本見 CLAUDE.md）。
4. 回頭把 `ai-rules/16-列印文件標準.md` 第三之四節「既有模組相容表」裡供應商稽核／教育訓練那兩列的狀態從 ⏳ 改成 ✅
   （若某幾張子表單判斷後確認是「多筆彙總清單、不適用」，也要把結論寫回去，不要留著 ⏳ 讓人以為還沒判斷）。
5. 在 `CLAUDE.md` 最上面加一行新的變更記錄（照現有格式，日期＋一句話說明＋起因）。
6. **確認以上都做完、都驗證過之後**：把這份檔案（`ai-rules/work/待補-AS編號版次依業務日期回推.md`）用 `git rm` 刪除、
   同時把 `CLAUDE.md` 路由表裡指向這份檔案的那一列也一併刪掉，兩個改動一起 commit。
   如果只完成一部分（例如只做完供應商稽核，教育訓練還沒做），**不要刪這份檔案、也不要刪路由表那一列**，把已完成的部分從
   上面待辦表格裡的狀態欄位改掉（⏳→✅ 或寫清楚判斷結論），留著沒做完的部分給下一次接手。
