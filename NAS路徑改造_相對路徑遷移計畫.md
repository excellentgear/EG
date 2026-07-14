# 附件路徑改造：絕對路徑存DB → 即時組路徑（情況二化）— 給 AI 的提示詞與逐模組任務清單

> 目的：讓「換 NAS / 換磁碟代號」時，舊附件不需要額外搬 SQL 就能繼續讀到。
> 本文可直接貼給 Claude（或其他 AI）執行。**先讀完「一、背景」「二、通用改法」，再照「三、逐模組任務」一次做一個模組。**
>
> 本次改造範圍 = 目前查出的「情況一」四個模組。若之後想連「情況三」（程式碼硬寫 Z:\ 的圖面檢視頁）一起處理，是另一批工作，先不在本文範圍內，需要再另外討論。

---

## 一、背景（先懂這個，不懂不要動手）

2026-07-13 盤點全站附件/圖檔路徑功能，分成三種存法：

- **情況一（本次要改的）**：上傳當下把「根路徑設定值＋子資料夾＋檔名」組成**完整絕對路徑**，直接寫死存進 DB 的 `file_path` 欄位。讀取/下載/刪除時**直接**拿 DB 存的那條路徑用。換 NAS 磁碟代號後，就算改了網頁設定，舊資料的 `file_path` 欄位還是寫著舊代號，會找不到檔案。
  - QA 異常單附件（`qa_abnormal_attachments`）
  - CAR 矯正單附件（`car_attachment`）
  - 公告附件（`live_event_file`，含 `preview_path` 快取版）
  - IR 退貨附件（`ir_attachments`）
- **情況二（本次要改成的樣子，已有現成範例可抄）**：DB 只存**檔名**（不存路徑），讀取時才用「當前設定值的根路徑」＋「即時算出的子資料夾」現場組出完整路徑。換代號後只要改一個設定值，舊資料全部抓得到。已經是這樣做、可以直接參考的模組：
  - 業務追蹤圖片 `sales_track_images`（`src/store/store_Sales_Track_API.php`，設定鍵 `sales_nas_dir`）
  - 料號附件 `part_attachments`（`Part_Attachment_API.php`，設定鍵 `part_attach_nas_dir`）
  - 技術備註圖片 `note_images`（`views/pages/master_data_management.php`，設定鍵 `notes_nas_dir`）
  - 訂單變更單附件 `order_change_attachment`（設定鍵 `order_change_attach_dir`）
- **情況三（不在本次範圍）**：路徑直接硬寫在程式碼裡（如 `bom_viewer.php` 的 `Z:/BOM/`），沒有存 DB，也沒有設定頁。

另有一份 `Z槽改UNC_提示詞與邏輯.md`：那份文件的目的是把 `Z:\` 換成 UNC 路徑，**當時刻意決定「DB 繼續存絕對路徑」**（該文件第26、29、43行）。本次改造跟那份文件**不衝突**——不管根路徑設定值是 `Z:\...` 還是 `\\excellentnas\...`（UNC），情況二的做法都一樣是「讀取時現場組」，兩件事可以先後做、互不影響。若專案還沒做完 UNC 那件事，本次改造一樣可以先做。

**本次改造完成後，情況二已正式定為全站規範**（見 `CLAUDE.md` 鐵律第5條、`ai-rules/07-附件路徑儲存規範.md`）——之後任何人／AI 要新增檔案路徑相關功能，一律直接照情況二寫，不用再等本次遷移全部做完。

---

## 二、通用改法模式

### 核心概念
1. **上傳／子資料夾命名邏輯完全不變**——不要去改「檔案實際存在哪個資料夾」這件事的規則，只改「之後要去讀這個檔案時，路徑字串從哪裡來」。
2. DB 的 `file_path` 欄位**可以繼續寫入**（上傳時照舊寫，不用刪欄位、不用改 schema），但**不再信任它的值**——所有「讀取/下載/刪除/預覽/浮水印加註」的地方，一律改成呼叫一個新的「路徑解析函式」，用「目前的根路徑設定」＋「即時算出的子資料夾」＋「DB 存的檔名（`file_name` 欄位，這個本來就只存檔名，不受影響）」現場組出路徑。
3. **不需要寫任何搬移舊資料的 SQL**。因為子資料夾名稱（如異常單號、CAR單號、公告編號、退貨單號）本來就是這筆附件關聯的單據自己的欄位，永遠查得到；只要新 NAS 上「資料夾名稱＋檔名」跟舊的一樣（使用者已經把整個資料夾複製過去），現場組出來的路徑就會是對的。**這比一般想像的「資料庫遷移」簡單很多，不要過度設計。**

### 範例（伪代码，實際要照各模組真實邏輯調整，不要照抄）
```php
// 改之前：直接信任 DB 存的絕對路徑
if (!is_file($att['file_path'])) jerr('附件不存在', 404);
readfile($att['file_path']);

// 改之後：現場組路徑
$fullPath = xxxAttResolvePath($db, $att); // 新函式：根路徑設定 + 子資料夾 + file_name
if (!is_file($fullPath)) jerr('附件不存在', 404);
readfile($fullPath);
```

### ⚠️ 動手前必須先跟使用者確認的地雷（不要自己猜）
- **單號是否可能事後被改名**：如果異常單號/CAR單號/公告編號/退貨單號在建立後可能被修改，而舊附件已經存在用「舊單號」命名的資料夾裡，那麼「現場用目前單號重組路徑」會找錯資料夾。動手前務必去確認（查程式碼是否有「改單號」功能，或直接問使用者）這幾種單號是否為不可變。若不確定，**先問，不要假設**。
- 每個模組的子資料夾命名規則**不完全一樣**（例如 CAR 會對單號做 `preg_replace('/[^A-Za-z0-9R]/','',...)` 清洗，QA 則不清洗，直接用原始單號），下面「三、逐模組任務」已經附上目前實際邏輯的行號，**照抄那個邏輯**，不要自己發明一套新規則。

---

## 三、逐模組任務清單（一次只做一個，做完等使用者測試通過再繼續）

### 執行規則（每個模組都要遵守）
1. 開始改某個模組前，**先在「NAS路徑改造_修改紀錄.md」把該模組狀態改成「進行中」**，寫下開始時間。
2. 依 `CLAUDE.md` 鐵律：改任何既有檔案前先 `Copy-Item 檔案 檔案.bak-yyyyMMdd-HHmm` 備份；改完 `php -l` 檢查語法；完成後寫入 `page_change_log`。
3. **改完一個模組就停下來**，不要接著改下一個。把改了哪些檔案、怎麼測試寫進「NAS路徑改造_修改紀錄.md」，明確請使用者去頁面上實測（見下方各模組的驗證清單），等使用者回覆「測試通過」才可以繼續下一個模組。
4. 若某個模組測試失敗，**不要自己嘗試改到「看起來能動」就算了**——把失敗現象記錄進修改紀錄檔，跟使用者確認是要繼續修，還是先恢復備份檔。
5. 若某一步驟需要修改共用函式（見下方「注意：共用函式」），要同時說明這會不會影響到還沒排到的模組，並請使用者留意。

### 建議處理順序（風險由低到高）
1. **CAR 矯正單附件**（最單純，無預覽快取、無 Telegram，全部邏輯在單一檔案）
2. **IR 退貨附件**（單純，但目前連設定頁都沒有，需要新增設定鍵）
3. **QA 異常單附件**（多了 Excel/Word 轉 PDF 的暫存流程、預覽快取、Telegram 兩段式附件發送）
4. **公告附件**（最複雜：預覽快取與 QA 共用同一個函式、還有回覆附件的巢狀子資料夾、Telegram 發送——牽動通知系統，務必最後做，且改完要完整測過通知/Telegram 沒壞掉）

---

### 模組 1：CAR 矯正單附件（`car_attachment`）

**子資料夾命名邏輯**（`src/store/store_CAR_API.php:1388-1391`）：
```php
$root = rtrim(car_setting($pdo, 'car_attach_root_path', ''), "\\/");
$sub = $carId ? preg_replace('/[^A-Za-z0-9R]/', '', (string)($o['car_no'] ?: ('ID' . $carId))) : ('_temp/' . $tempKey);
$dir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sub);
```
綁定單據後用「清洗過的 car_no」；未綁定（暫存）用 `_temp/{tempKey}`。

**需要新增的解析函式**：例如 `carAttResolvePath($pdo, array $row): string`，輸入需含 `car_id`（或 join 出 `car_no`）、`temp_key`、`file_name`，內部照上面同一套規則重組路徑。

**目前直接信任 `file_path` 欄位、需要改用新函式的地方**：
- 下載：`store_CAR_API.php:1414`（`is_file($a['file_path'])`）、`:1427`（`readfile($a['file_path'])`）
- 刪除：`store_CAR_API.php:1439`（`is_file($a['file_path'])` / `unlink($a['file_path'])`）

**驗證清單（改完請使用者做）**：
1. 開一張既有 CAR 單，下載一個舊附件 → 確認能正常下載且內容正確。
2. 在該單再上傳一個新附件，馬上下載 → 確認正常。
3. 刪除一個附件 → 確認 DB 紀錄與實體檔案都被清掉。
4. 暫存（未綁定單號）狀態上傳附件、再正式建單 → 確認附件仍能下載。

---

### 模組 2：IR 退貨附件（`ir_attachments`）

**目前狀況比較特殊**：根路徑**沒有設定頁，直接寫死在程式碼**（`src/store/store_IR_Track_API.php:1018-1020`）：
```php
function getIrFolder(string $irNo): string {
    return 'Z:\\BOM\\ERP\\業務\\退貨資料' . DIRECTORY_SEPARATOR . $irNo;
}
```
子資料夾＝ `$irNo`（IR 單號；`saveIrFiles()` 呼叫時是 `$irNo ?: (string)$irId`，單號未生成前先用內部 ID）。

**這個模組要多做一步**：先新增一個 `system_settings` 設定鍵（例如 `ir_attach_root_path`，預設值沿用現行 `Z:\BOM\ERP\業務\退貨資料`），並在 `views/Sales/IR_Track.php` 仿照既有「異常單附件儲存根目錄」那個設定欄位（可參考 `IR_Track.php:291-295` 及 `loadAttachRootPath()`/`saveAttachRootPath()` 那組函式）加一個對應的輸入框。

**需要新增的解析函式**：例如 `irAttResolvePath($pdo, array $row): string`，用新設定鍵讀根路徑＋（透過 `ir_id` join `ir_track.IR_no` 或已知的 `$irNo`）＋ `file_name`。

**目前直接信任 `file_path` 欄位、需要改用新函式的地方**：
- `store_IR_Track_API.php:929`（`file_exists($att['file_path'])` / `unlink`）
- `store_IR_Track_API.php:975`（同上）
- 另外還要找出「下載/檢視退貨附件」的實際端點（若上面清單沒列全，動手前先 `grep file_path store_IR_Track_API.php` 完整核對一次，不要只照本文清單）

**驗證清單**：
1. 新增的設定欄位存了值之後，重新整理頁面能讀回同一個值。
2. 既有退貨單的舊附件能正常下載/檢視。
3. 新上傳一個附件能正常存取、刪除正常。
4. 進度回覆（`note_id` 非 NULL）的附件也測一次。

---

### 模組 3：QA 異常單附件（`qa_abnormal_attachments`）

**子資料夾命名邏輯**（`store_QA_Abnormal_API.php:574-583`，`att_upload` 動作在 `:636-644` 是同一套邏輯重複一次，兩處都要一起改）：
```php
$rootPath = getQASetting($db, 'attach_root_path') ?: 'Z:\BOM\ERP\品管\異常單附件';
$folder = $orderId > 0 && $orderNo
    ? rtrim($rootPath,'\\/') . DIRECTORY_SEPARATOR . $orderNo                              // 已綁定
    : rtrim($rootPath,'\\/') . DIRECTORY_SEPARATOR . 'temp' . DIRECTORY_SEPARATOR . $tempKey; // 暫存
```
注意：QA 這裡**沒有**對 `$orderNo` 做字元清洗（跟 CAR 不一樣，不要套用 CAR 的清洗規則）。

**這個模組比 CAR 多兩件事，都要一併處理**：
1. **暫存轉檔佇列**（Excel/Word → PDF）：`eg_att_pending_*` 系列函式（`store_QA_Abnormal_API.php` 內 `att_upload`/`att_convert`/`att_preview`/`att_commit`/`att_discard` 動作，行號約 621-725）目前都是先用 `$rootPath` 現場組路徑操作暫存區，這部分**本來就沒有寫死絕對路徑進 DB**（暫存階段用 `upload_id` 查 meta.json，不是查 DB），可以先確認這段是否真的不受影響，若確認無誤則不用動。
2. **檢視快取版（浮水印/角落標註）**：`src/common/attachment_lib.php` 的 `eg_att_make_preview()`（第617-653行）目前用 `$row['file_path']`（第618行）當來源檔，且這個函式**是 QA 與公告共用**（第648行依 `$scope` 切換資料表）。改這裡時：
   - `eg_att_make_preview($db, $scope, $row)` 的 `$row` 需要能推出正確子資料夾（目前呼叫端 `eg_att_refresh_preview()` 只是 `SELECT * FROM table WHERE id=?`，沒有 join 出單號），要調整成連單號一起查出來，或在函式內部另外查。
   - 因為這個函式公告模組也在用，**改這裡等於順便動到模組 4 的一部分**，做完模組 3 測試時，也請使用者順便確認公告附件的「角落標註檢視」功能沒有壞掉（即使公告模組本身還沒排到）。
3. **Telegram 兩段式附件下載**（`telegram/poll_core.php:207-263`）：`att:q:{id}` 會 `SELECT f.*, o.abnormal_order_no AS doc_no, o.notify_event_id AS event_id FROM qa_abnormal_attachments f JOIN qa_abnormal_order o ON o.id = f.abnormal_order_id WHERE f.id = ?`（第210行，已經 join 出 `doc_no`＝單號，剛好可以直接拿來組路徑），第214/227/250/252行都直接用 `$att['file_path']`，要改成用解析函式。

**需要新增的解析函式**：例如 `qaAttResolvePath($db, array $row): string`（`$row` 需含 `abnormal_order_id`/`temp_key`/`file_name`，若沒 join 到 order_no 要在函式內部再查一次）。

**其他直接信任 `file_path` 的地方**（動手前用 `grep -n "file_path" store_QA_Abnormal_API.php` 完整核對，這裡列出已知的）：
- 刪除：`:767`（`unlink($att['file_path'])`）
- 暫存轉正式時的搬移：`:1470-1482`（`linkTempAttachments()`，這段是把暫存資料夾整批 rename 到正式資料夾，邏輯較特殊，改動時要特別小心不要破壞「搬檔案」這個動作本身，只需確認搬完之後新寫入的 `file_path`／子資料夾邏輯跟解析函式算出來的一致）

**驗證清單**：
1. 既有異常單的舊附件（圖片/PDF）能下載、能看到角落標註快取版。
2. 新上傳一張圖片，立刻能看到快取版產生。
3. 上傳 Excel/Word，跑轉檔流程，轉完能正常預覽、確認送出後能查到附件。
4. 刪除附件正常。
5. 暫存（未開單前）上傳附件，正式建單後 → 附件正確搬到正式資料夾，能下載。
6. 找一張有設定「允許 Telegram 推播」標籤的附件，透過異常單通知走 Telegram「取得附件」流程，確認能正確收到檔案。
7. **附帶確認公告模組的角落標註檢視功能沒被連帶弄壞**（因為共用了 `eg_att_make_preview`）。

---

### 模組 4：公告附件（`live_event_file`）— 最後做

**子資料夾命名邏輯**（`src/common/notice_files.php:53-67`）：
```php
function eg_notice_safe_seg($s) { /* 移除路徑不安全字元，保留中文 */ }
function eg_notice_event_dir($db, $eventNo) {
    return eg_notice_base($db) . DIRECTORY_SEPARATOR . eg_notice_safe_seg($eventNo);
}
function eg_notice_reply_dir($db, $eventNo, $replier, $serial) {
    $folder = eg_notice_safe_seg($eventNo) . '-' . eg_notice_safe_seg($replier) . '-' . sprintf('%03d', (int)$serial);
    return eg_notice_event_dir($db, $eventNo) . DIRECTORY_SEPARATOR . '回覆附件' . DIRECTORY_SEPARATOR . $folder;
}
```
公告本身附件在 `{base}\{公告編號}\`；回覆附件在 `{base}\{公告編號}\回覆附件\{公告編號}-{回覆人}-{流水號}\`——**兩層子資料夾邏輯都要各自處理**，回覆附件不能只套用公告附件那一層的規則。

**這個模組要動的地方**：
1. `eg_att_make_preview()`（`attachment_lib.php:618`，見模組3說明，若模組3已經改過這個共用函式，這裡主要是確認公告的呼叫端有正確傳入 `event_no` 等資訊）。
2. Telegram：`telegram/poll_core.php:208`（`att:e:` 分支，已 join `le.event_no AS doc_no`）同樣的 `file_path` 直接使用問題（第214/227/250/252行，跟模組3是同一段程式碼、共用邏輯，但要分別確認 `e`/`q` 兩個分支都測到）。
3. 檢查是否有其他讀取端點（例如 `_eventFile.php` 之類，用來服務公告檢視頁的 `show_attach_inline`／燈箱功能）直接用 `file_path`/`preview_path`——動手前先 `grep -rn "file_path\|preview_path" src/store/_eventFile.php views/liveEvent/` 完整核對，不要漏掉檢視頁。
4. 回覆附件是否走同一張 `live_event_file` 表、同一套上傳/讀取程式碼，還是另有獨立邏輯——動手前先確認清楚（如不確定就問使用者或再花時間查證，不要假設跟公告本身附件共用同一套函式）。

**驗證清單**：
1. 既有公告的舊附件能下載、檢視、角落標註快取版正常。
2. 既有「回覆附件」（巢狀子資料夾）能下載、檢視。
3. 新增公告、上傳附件 → 正常。
4. 新增回覆、上傳附件 → 正常。
5. `show_attach_inline` 開啟的公告，附件在檢視頁直接顯示正常。
6. Telegram「取得附件」流程（`att:e:`）正常收到檔案，且加註後的個人溯源浮水印正確。
7. 完整跑一次公告通知的推播測試（Web Push + Telegram），確認整個通知系統沒有因為這次改動而壞掉——這是通知系統核心，務必仔細測。

---

## 四、給執行 AI 的開場提示詞（可直接複製使用）

```
請讀 c:\MAMP\htdocs\EGsystem\NAS路徑改造_相對路徑遷移計畫.md 與 NAS路徑改造_修改紀錄.md 這兩份文件。
今天只做「三、逐模組任務清單」裡狀態為「未開始」、順序最前面的那一個模組，不要一次做多個。
動手前：
1. 依 CLAUDE.md 鐵律備份要改的檔案。
2. 若文件裡標註「⚠️ 需要先確認」的地雷（例如單號是否可能改名），先跟我確認清楚再動手，不要自己假設。
3. 依文件描述的邏輯新增路徑解析函式，把所有信任 DB file_path 欄位的讀取/下載/刪除/預覽點改成呼叫該函式。
4. 不要改動任何上傳/子資料夾命名的既有規則。
5. 改完後 php -l 檢查語法，並更新 NAS路徑改造_修改紀錄.md（狀態、改動檔案、時間）。
6. 列出該模組的驗證清單給我，然後停下來等我實測回報，不要接著做下一個模組。
```
