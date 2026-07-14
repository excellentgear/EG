# 把「Z: 磁碟機路徑」改成「UNC 路徑」— 給 Claude 的提示詞與邏輯

> 目的：讓透過 **Web(Apache/PHP)** 存取 NAS 的功能更穩定。
> 本文可直接貼給 Claude 執行。**先讀完「背景與原則」，再照「待改清單」逐一處理。**

---

## 一、背景與原則（務必先懂）

1. **檔案是由「伺服器(Apache/PHP)」讀寫，不是使用者的電腦。**
   使用者上傳 → 送到伺服器(192.168.2.128) → **伺服器**寫到 NAS。所以只看伺服器的 NAS 存取權，與各使用者 PC 是否有 Z: 無關。

2. **`Z:`（磁碟機代號）是「登入 session 專屬」的對應，對 Apache 不穩定。**
   - 實測：從 Web PHP 呼叫 `is_dir('Z:\\')`（磁碟機根目錄）會**讓 PHP 程序崩潰**（回應為空、Content-Length:0）。
   - 但**寫入 Z: 的「子路徑」**（如 `Z:\BOM\ERP\...\子夾`）目前可運作（因 Apache 跑在使用者登入 session 下）。
   - 風險：若日後把 Apache 改成「Windows 服務/開機自動啟動」，就沒有 Z: 對應 → 全部失效。

3. **UNC 路徑 `\\主機\分享\...` 不依賴磁碟機對應，較穩定，是建議做法。**
   - 本站 `Z:` 對應到 **`\\excellentnas\生產課`**（已用 `Get-WmiObject Win32_LogicalDisk` 確認）。
   - 換算：`Z:\BOM\ERP\業務\退貨資料` → `\\excellentnas\生產課\BOM\ERP\業務\退貨資料`
     （`Z:/BOM/...` 斜線版同理，UNC 用反斜線 `\\excellentnas\生產課\BOM\...`）
   - 實測：Web PHP 用 UNC `@mkdir()` + `move_uploaded_file()` / `file_put_contents()` **寫入成功**，且使用者在自己的 Z: 也看得到同一批檔（同一個 NAS 位置）。

4. **改寫時的注意事項：**
   - **不要**對磁碟機根 `Z:\` 或 UNC 根 `\\主機\分享` 呼叫 `is_dir()`/`is_writable()`/`realpath()` 來「先檢查」——UNC 的這些判斷常回傳 false 卻其實可寫。**改為直接嘗試 `@mkdir($dir,0775,true)` 再看 `move_uploaded_file()`/`file_put_contents()` 的實際結果**判斷成敗。
   - 存到 DB 的路徑請存**完整絕對路徑**（UNC），讀取/下載時直接 `readfile($absPath)`。
   - Windows 上 UNC 用反斜線；PHP 字串內每個反斜線要 `\\`。**設定值存 DB 時**建議用 `chr(92)` 組字串，避免引號轉義把 `\\` 吃成 `\`（本專案 system_settings 曾遇過）。
   - 路徑防穿越：`if (strpos($p,'..')!==false) 拒絕；`只接受在設定的基礎路徑底下。
   - **搬遷既有檔案不在本次範圍**：只改「之後新存的路徑」；舊資料若之前存 Z: 絕對路徑，讀取時 Z: 子路徑目前仍可讀，或另行搬移。

---

## 二、可貼給 Claude 的提示詞（範本）

```
把 EGsystem 專案中「用 Z: 磁碟機存取 NAS」的功能改成「UNC 路徑」，以提升 Apache 存取穩定性。

規則：
- Z: 對應 \\excellentnas\生產課。換算：Z:\A\B → \\excellentnas\生產課\A\B（Z:/A/B 亦同，UNC 用反斜線）。
- 不要對磁碟機根或 UNC 根呼叫 is_dir/is_writable/realpath 來預檢；改為直接 @mkdir(...,true) 後，以 move_uploaded_file/file_put_contents 的實際結果判斷。
- 若路徑存在 system_settings（如 sales_nas_dir、attach_root_path、notes_nas_dir 等），把預設值與現有值改成 UNC；設定值寫入 DB 時用 chr(92) 組反斜線，避免被轉義。
- 存 DB 的檔案路徑用完整 UNC 絕對路徑；讀取用 readfile。
- 保留舊資料相容（舊的 Z: 絕對路徑讀取仍可用）。
- 每改一個檔案，先用瀏覽器/HTTP 實測「上傳→寫入 NAS→再讀回」成功，再繼續下一個。
- 完成後把本次修改寫入 page_change_log。

請先 grep 全專案 Z:\ 與 Z:/ 的使用點，分「儲存(寫入)」與「掃描(讀取)」兩類；優先改「儲存」類。逐檔提出修改並實測。
```

---

## 三、待改清單（已掃描，供參考）

### A. 有「寫入/儲存」到 Z: 的（優先改）
| 檔案 | 目前路徑 | 建議 UNC |
|------|----------|----------|
| `src/store/store_IR_Track_API.php`（退貨） | 硬編碼 `Z:\BOM\ERP\業務\退貨資料`（約 1019 行） | `\\excellentnas\生產課\BOM\ERP\業務\退貨資料` |
| `src/store/store_QA_Abnormal_API.php`（品管異常） | 設定 `attach_root_path` 預設 `Z:\BOM\ERP\品管\異常單附件`（374、602 行） | 同上換 UNC；並更新該設定值 |
| `src/store/store_Sales_Track_API.php`（業務） | 設定 `sales_nas_dir` = `Z:/BOM/ERP/業務/`（99、746… 行） | `\\excellentnas\生產課\BOM\ERP\業務\` |
| `views/pages/master_data_management.php`（技術筆記） | 設定 `notes_nas_dir` = `Z:/BOM/ERP/技術/`（876、1212 行） | `\\excellentnas\生產課\BOM\ERP\技術\` |
| `src/store/_OrderChange_API.php`（訂單變更附件） | 依 `attach_dir` 設定（第 358、510 行附近，儲於 order_change_attachment） | 確認其設定路徑改 UNC |

> 對應的前端輸入框預設字串也要一起改：`views/Sales/IR_Track.php`、`views/Sales/Sales_Track.php`、`views/Sales/Sales_Track_test.php` 內的 `Z:\...`/`Z:/...` 範例字串。

### B. 只「讀取/掃描」Z:（BOM 圖面等，風險較低，可一併改）
`views/pm/bom_viewer.php`、`bom_download.php`、`part_viewer.php`、`Transfer_Log_Analysis.php`、`X Product_Profit_Analysis.php`、
`views/Sales/Shipping_Analysis.php`、`Shipping_Analysis_new.php`、`NewOrder_Track.php`、`NewOrder_Track222.php`、
`views/QC/inspection_result_entry.php`、`views/pm/OreadyReply_ForPm_BaseOfTime2_ajax.php`、`views/pages/master_data_management.php`
等內的 `$scan_dir = 'Z:/BOM/'`（掃描 NAS 圖檔）→ 改 `\\excellentnas\生產課\BOM\`。
> 讀取類若目前運作正常可暫緩；但為統一與抗「Apache 服務化」風險，建議最終一起改。

### C. 已完成（本專案公告通知）— 可當範例參考
- 共用工具：`src/common/notice_files.php`（UNC 相容：@mkdir + move、DB 存絕對路徑、eg_notice_abs_path 防穿越）
- 設定：`system_settings.notice_attach_base` = `\\excellentnas\生產課\BOM\ERP\公告通知\公告通知附件`（設定跳窗於 公告/通知管理 頁）
- 端點：`src/store/_noticeSettings.php`（含「儲存並測試寫入」）、`_eventFile.php`（讀取/預覽）

---

## 四、驗證方式（每檔改完必做）
1. 用瀏覽器在該頁上傳一個小檔 → 成功。
2. 到伺服器（或用有 Z: 對應的機器）確認檔案出現在對應 NAS 資料夾。
3. 在該頁把檔案下載/預覽 → 成功。
4. 失敗時看 mkdir/move 回傳值，不要用 is_dir 預檢誤判。
