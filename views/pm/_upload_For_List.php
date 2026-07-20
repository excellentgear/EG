<?php
session_start();

include '../../src/common/DBConnection.php';
include '../../src/common/_config.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../../vendor/autoload.php'; // 確認引用正確路徑
require_once '../../src/common/qc_form_generator.php'; // QC 檢驗紀錄表 .xlsm 產生器（BOM ERP匯入用）
use PhpOffice\PhpSpreadsheet\IOFactory; // 使用 PhpSpreadsheet 的 IOFactory 來載入 Excel 檔案

// 開啟輸出緩衝，避免 header() 前有輸出導致錯誤
ob_start();

// 記錄上傳操作到 system_settings
if (!function_exists('recordUploadLog')) {
    function recordUploadLog($db, $key) {
        try {
            $uid   = (int)($_SESSION['id'] ?? 0);
            $uname = $_SESSION['user_cname'] ?? ($_SESSION['userName'] ?? '');
            $db->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by, updated_at)
                          VALUES (?, '', ?, ?, NOW())
                          ON DUPLICATE KEY UPDATE updated_by_id=VALUES(updated_by_id), updated_by=VALUES(updated_by), updated_at=NOW()")
               ->execute([$key, $uid ?: null, $uname]);
        } catch (Exception $e) {}
    }
}

// 更新訂單列表 (ORDER_LIST)
if ($_GET['but'] == 'Order') {

    // 輔助函數：處理輸入值
    function quoteValue($value)
    { // 將空字串或 'NULL' 字串轉換為實際的 null 值
        if (is_null($value)) {
            return null;
        }
        $value = trim($value);
        return ($value === '' || strtoupper($value) === 'NULL') ? null : $value;
    }
    // Helper function to convert date formats
    function convertDateFormat($date)
    { // 輔助函數：轉換日期格式
        if (empty($date)) {
            return null;
        }
        if (is_numeric($date)) { // 如果是數字格式的 Excel 日期
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($date)
                ->format('Y-m-d H:i:s');
        }
        // 嘗試解析常見的字串格式
        $formatsToTry = [
            'Y/n/j g:i:s A',
            'Y/n/j G:i:s',
            'Y/n/j H:i:s',
            'Y/m/d H:i:s',
            'Y-m-d H:i:s',
            'n/j/Y g:i:s A',
            'n/j/Y G:i:s',
            'n/j/Y H:i:s',
            'm/d/Y H:i:s',
            'Y-m-d',
            'Y/m/d',
            'n/j/Y',
            'm/d/Y'
        ];

        foreach ($formatsToTry as $format) {
            $dateTime = DateTime::createFromFormat($format, trim($date));
            if ($dateTime) {
                // 如果格式僅為日期，則將時間設為 00:00:00
                if (strpos($format, 'H') === false && strpos($format, 'G') === false && strpos($format, 'g') === false && strpos($format, 'h') === false) {
                    $dateTime->setTime(0, 0, 0);
                }
                return $dateTime->format('Y-m-d H:i:s');
            }
        }
        // 若以上格式皆不符，嘗試使用 strtotime 解析簡單日期字串 (可能不含時間)
        if (strtotime(trim($date)) !== false) {
            return date('Y-m-d H:i:s', strtotime(trim($date)));
        } else {
            return null;
        }
    }
    if (isset($_FILES['file']['name'])) {
        $file = $_FILES['file']['tmp_name'];
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray();

        $header = array_shift($data); // Get and remove header row
        $excelRows = $data; // 取得並移除標頭列

        $consoleLogs = array();
        $userId = $_SESSION['id'] ?? 'excel_import_user';

        try {
            // 1. 建立並清空暫存表 temp_order_list (移至交易開始前)
            // ✅ 修改：在暫存表中增加 Open_Qty 欄位
            $db->exec("CREATE TEMPORARY TABLE IF NOT EXISTS temp_order_list (
                Order_oo VARCHAR(255), d_id VARCHAR(255), Specification VARCHAR(255), Order_ps TEXT, Client_name VARCHAR(255), Qty INT,
                Order_date DATETIME, Delivery_date DATETIME, Delivery_date_2 DATETIME, Delivery_date_3 DATETIME, 
                unit_price DECIMAL(10,2), currency VARCHAR(30), exchange_rate DECIMAL(10,6),
                Order_status VARCHAR(50), Created_By VARCHAR(255), Created_At DATETIME, Modified_By VARCHAR(255), Modified_At DATETIME,
                Open_Qty INT
            )");
            $db->exec("TRUNCATE TABLE temp_order_list");
            $consoleLogs[] = "暫存表 temp_order_list 已建立並清空。";

            // 在暫存表的 DDL 操作後開始交易
            $db->beginTransaction();

            // 準備插入 temp_order_list 的 SQL 語句
            // ✅ 修改：在 INSERT 語句中增加 Open_Qty 欄位與對應的 placeholder
            $tempInsertSql = "INSERT INTO temp_order_list (
                Order_oo, d_id, Specification, Order_ps, Client_name, Qty,
                Order_date, Delivery_date, Delivery_date_2, Delivery_date_3, 
                unit_price, currency, exchange_rate,
                Order_status, Created_By, Created_At, Modified_By, Modified_At, Open_Qty
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $tempInsertStmt = $db->prepare($tempInsertSql);

            // 填入資料到 temp_order_list
            foreach ($excelRows as $rowIndex => $row) {
                if (empty($row[0]) && empty($row[1])) { // 如果 Order_oo 和 d_id 都為空，則跳過
                    $consoleLogs[] = "跳過空行 " . ($rowIndex + 1);
                    continue;
                }
                // ✅ 修改：確保列有 19 欄 (A-S)，若較短則以 null 填充
                $paddedRow = array_pad($row, 19, null);

                $excel_order_date = convertDateFormat(quoteValue($paddedRow[6]));
                $excel_delivery_date = convertDateFormat(quoteValue($paddedRow[7]));
                $excel_delivery_date_2 = convertDateFormat(quoteValue($paddedRow[8]));
                $excel_delivery_date_3 = convertDateFormat(quoteValue($paddedRow[9]));
                $excel_created_at = convertDateFormat(quoteValue($paddedRow[15]));
                $excel_modified_at = convertDateFormat(quoteValue($paddedRow[17]));

                // ✅ 修改：修正 execute() 中的參數列表，使其與 SQL 語句的 placeholder 數量 (19) 和順序完全對應
                $tempInsertStmt->execute([
                    quoteValue($paddedRow[0]),
                    quoteValue($paddedRow[1]),
                    quoteValue($paddedRow[2]),
                    quoteValue($paddedRow[3]),
                    quoteValue($paddedRow[4]),
                    quoteValue($paddedRow[5]),
                    $excel_order_date,
                    $excel_delivery_date,
                    $excel_delivery_date_2,
                    $excel_delivery_date_3,
                    quoteValue($paddedRow[10]), // unit_price
                    quoteValue($paddedRow[11]), // currency
                    quoteValue($paddedRow[12]), // exchange_rate
                    quoteValue($paddedRow[13]), // Order_status
                    quoteValue($paddedRow[14]) ?: $userId, // Created_By
                    $excel_created_at ?: date('Y-m-d H:i:s'), // Created_At
                    quoteValue($paddedRow[16]) ?: $userId, // Modified_By
                    $excel_modified_at ?: date('Y-m-d H:i:s'), // Modified_At
                    quoteValue($paddedRow[18]) // Open_Qty
                ]);
            }
            $consoleLogs[] = "資料已匯入 temp_order_list。";

            // 2. 從 temp_order_list 取得不重複的 (Order_oo, d_id) 群組
            $groupStmt = $db->query("SELECT DISTINCT Order_oo, d_id FROM temp_order_list");
            $groups = $groupStmt->fetchAll(PDO::FETCH_ASSOC);

            $updateCount = 0;
            $insertCount = 0;
            $closeCount = 0;
            foreach ($groups as $group) {
                $currentOrderOo = $group['Order_oo'];
                $currentDid = $group['d_id'];

                // 從暫存表取得此群組的新資料
                $newOrdersStmt = $db->prepare("SELECT * FROM temp_order_list WHERE Order_oo = ? AND d_id = ? ORDER BY Delivery_date ASC, Order_oo ASC"); // Changed Qty to Order_oo for sort stability based on order identifier
                $newOrdersStmt->execute([$currentOrderOo, $currentDid]);
                $newOrdersData = $newOrdersStmt->fetchAll(PDO::FETCH_ASSOC);

                // Fetch old open orders for this group from main table
                $oldOrdersStmt = $db->prepare("SELECT * FROM order_list WHERE Order_oo = ? AND d_id = ? AND (Order_status IS NULL OR Order_status != '9') ORDER BY Delivery_date ASC, Order_id ASC");
                $oldOrdersStmt->execute([$currentOrderOo, $currentDid]);
                $oldOpenOrders = $oldOrdersStmt->fetchAll(PDO::FETCH_ASSOC);

                $nIdx = 0;
                $oIdx = 0;

                while ($nIdx < count($newOrdersData) || $oIdx < count($oldOpenOrders)) {
                    $currentNewData = $nIdx < count($newOrdersData) ? $newOrdersData[$nIdx] : null;
                    $currentOldOrder = $oIdx < count($oldOpenOrders) ? $oldOpenOrders[$oIdx] : null;

                    if ($currentNewData && $currentOldOrder) { // 更新
                        // ✅ 修改：在 UPDATE 語句中增加 Open_Qty 欄位
                        $updateSql = "UPDATE order_list SET 
                                        Specification = ?, Order_ps = ?, Client_name = ?, Qty = ?, Order_date = ?,
                                        Delivery_date_3 = Delivery_date_2, Delivery_date_2 = Delivery_date, Delivery_date = ?,
                                        unit_price = ?, currency = ?, exchange_rate = ?, Open_Qty = ?,
                                        Modified_By = ?, Modified_At = NOW()
                                      WHERE Order_id = ?";
                        $updateMainStmt = $db->prepare($updateSql);
                        // ✅ 修改：在 execute() 中增加 Open_Qty 的值
                        $updateMainStmt->execute([
                            $currentNewData['Specification'],
                            $currentNewData['Order_ps'],
                            $currentNewData['Client_name'],
                            $currentNewData['Qty'],
                            $currentNewData['Order_date'],
                            $currentNewData['Delivery_date'], // New primary delivery date
                            $currentNewData['unit_price'],
                            $currentNewData['currency'],
                            $currentNewData['exchange_rate'],
                            $currentNewData['Open_Qty'],
                            $currentNewData['Modified_By'] ?: $userId,
                            $currentOldOrder['Order_id']
                        ]);
                        $updateCount++;
                        $nIdx++;
                        $oIdx++;
                    } elseif ($currentNewData) { // 插入
                        // ✅ 修改：在 INSERT 語句中增加 Open_Qty 欄位與對應的 placeholder
                        $insertSql = "INSERT INTO order_list (
                                        Order_oo, d_id, Specification, Order_ps, Client_name, Qty, Order_date, Delivery_date, Delivery_date_2, Delivery_date_3,
                                        unit_price, currency, exchange_rate, Open_Qty,
                                        Order_status, Created_By, Created_At, Modified_By, Modified_At
                                      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $insertMainStmt = $db->prepare($insertSql);
                        // ✅ 修改：在 execute() 中增加 Open_Qty 的值
                        $insertMainStmt->execute([
                            $currentNewData['Order_oo'],
                            $currentNewData['d_id'],
                            $currentNewData['Specification'],
                            $currentNewData['Order_ps'],
                            $currentNewData['Client_name'],
                            $currentNewData['Qty'],
                            $currentNewData['Order_date'],
                            $currentNewData['Delivery_date'],
                            $currentNewData['Delivery_date_2'],
                            $currentNewData['Delivery_date_3'],
                            $currentNewData['unit_price'],
                            $currentNewData['currency'],
                            $currentNewData['exchange_rate'],
                            $currentNewData['Open_Qty'],
                            $currentNewData['Order_status'] ?: null, // Default to open
                            $currentNewData['Created_By'] ?: $userId,
                            $currentNewData['Created_At'] ?: date('Y-m-d H:i:s'),
                            $currentNewData['Modified_By'] ?: $userId,
                            $currentNewData['Modified_At'] ?: date('Y-m-d H:i:s')
                        ]);
                        $insertCount++;
                        $nIdx++;
                    } elseif ($currentOldOrder) { // Close
                        $closeStmt = $db->prepare("UPDATE order_list SET Order_status = '9', Modified_At = NOW(), Modified_By = ? WHERE Order_id = ?"); // 結案
                        $closeStmt->execute([$userId, $currentOldOrder['Order_id']]);
                        $closeCount++;
                        $oIdx++;
                    } else {
                        break; // 理論上不應發生
                    }
                }
            }

            // 3. 批次結案 Excel 檔案中已不存在的群組訂單
            $batchCloseSql = "UPDATE order_list o
                                LEFT JOIN temp_order_list t
                                  ON o.Order_oo = t.Order_oo AND o.d_id = t.d_id
                                SET 
                                  o.Order_status = '9',
                                  o.Modified_At  = NOW(),
                                  o.Modified_By  = :userId
                                WHERE 
                                  (o.Order_status IS NULL OR o.Order_status <> '9')
                                  AND o.Delivery_date IS NOT NULL 
                                  AND t.Order_oo IS NULL"; // t.Order_oo IS NULL 表示該群組 (Order_oo, d_id) 不在 temp_order_list 中

            $batchCloseStmt = $db->prepare($batchCloseSql);
            $batchCloseStmt->bindParam(':userId', $userId, PDO::PARAM_STR);
            $batchCloseStmt->execute();
            $batchClosedCount = $batchCloseStmt->rowCount();
            $closeCount += $batchClosedCount; // 加到總結案筆數
            $consoleLogs[] = "批次結案 {$batchClosedCount} 筆 Excel 中已不存在的群組訂單。";

            $db->commit();
            $SUSS_MAG = "同步完成：新增 {$insertCount} 筆，更新 {$updateCount} 筆，結案 {$closeCount} 筆。";
            $consoleLogs[] = $SUSS_MAG;
        } catch (PDOException $e) {
            if ($db->inTransaction()) { // 若仍在交易中，則回滾
                $db->rollBack();
            }
            $SUSS_MAG = "資料庫操作失敗: " . $e->getMessage();
            $consoleLogs[] = $SUSS_MAG;
            $consoleLogs[] = "堆疊追蹤: " . $e->getTraceAsString();
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack(); // 若一般例外發生時仍在交易中，也回滾
            }
            $SUSS_MAG = "處理檔案時發生錯誤: " . $e->getMessage();
            $consoleLogs[] = $SUSS_MAG;
            $consoleLogs[] = "堆疊追蹤: " . $e->getTraceAsString();
        }

        $encodedMessage = urlencode($SUSS_MAG);

        // 輸出所有日誌到 F12 主控台，然後立即透過 JavaScript 轉跳
        echo "<script>";
        foreach ($consoleLogs as $log) {
            // Escape strings for JavaScript, especially for multi-line stack traces
            echo "console.log(" . json_encode(str_replace(["\r\n", "\n", "\r"], "\\n", $log)) . ");";
        }
        echo "window.location.href = 'Upload_List.php?message=oth&msg=" . $encodedMessage . "';";
        echo "</script>";

        ob_end_flush();
        exit;
    } else {
        $SUSS_MAG = '請上傳檔案！'; // 若未上傳檔案
        echo "<script>console.log(" . json_encode($SUSS_MAG) . "); window.location.href = 'Upload_List.php?message=oth&msg=" . urlencode($SUSS_MAG) . "';</script>";
        ob_end_flush();
        exit;
    }
};


// 更新報工_包含tg 2025.02.21 OK
if ($_GET['but'] == 'reply_tg') {
    function quoteValue($value)
    {
        if (is_null($value) || $value === '' || strtoupper($value) === 'NULL') {
            return null;
        } else {
            return $value;
        }
    }

    function convertDateFormat($date)
    {
        $dateTime = DateTime::createFromFormat('Y-m-d H:i:s', $date);
        if ($dateTime) {
            return $dateTime->format('Y-m-d H:i:s');
        } else {
            return null;
        }
    }

    if (isset($_FILES['file']['name'])) {
        $file = $_FILES['file']['tmp_name'];
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray();

        // 跳過標頭列
        $data = array_slice($data, 1);

        // 插入資料
        $stmt = $db->prepare("INSERT INTO `reply_all`(`reply_id`, `BOM`, `bom_ing_id`, `ps`, `Client_Name`, `sqty`, `oready_sqty`, `ProcessNo`, `MakerId`, `ok_sqty`, `ng_sqty`, `ng_id`, `ng_sqty2`, `ng_id2`, `ng_sqty3`, `ng_id3`, `m`, `t`, `width`, `mc_id`, `mc_time`, `machine_id`, `change_tool`, `processing_time`, `completed`, `Created_By`, `Created_At`, `Modified_By`, `Modified_At`)
        VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $checkStmt = $db->prepare("SELECT COUNT(*) FROM reply_all WHERE bom_ing_id = ? AND oready_sqty = ? AND MakerId = ? AND ok_sqty = ?");

        $SUSS_MAG = "";
        $successCount = 0;

        foreach ($data as $row) {
            // 確保每列資料的長度不超過20格
            if (count($row) > 29) {
                $row = array_slice($row, 0, 28);
            } elseif (count($row) == 29) {
                $row = array_slice($row, 1);
            }

            // 轉換日期格式 (若有)
            if (isset($row[17])) {
                $row[17] = convertDateFormat($row[17]);
            }
            if (isset($row[19])) {
                $row[19] = convertDateFormat($row[19]);
            }

            // 將值轉換為適當的格式 (例如，將空字串轉為 null)
            $quotedRow = array_map('quoteValue', $row);

            if (count($quotedRow) == 28) {
                // 顯示檢查語句及其參數 (除錯用)
                echo "正在檢查的資料：bom_ing_id = {$quotedRow[1]}, oready_sqty = {$quotedRow[5]}, MakerId = {$quotedRow[7]}, ok_sqty = {$quotedRow[8]}<br>";
                $checkStmt->execute([$row[1], $row[5], $row[7], $row[8]]);

                if ($checkStmt->fetchColumn() > 0) {
                    $SUSS_MAG .= $row[0] . " 資料已存在未輸入\n";
                } else {
                    // 插入資料
                    $stmt->execute($quotedRow);
                    $successCount++;
                }
            } else {
                echo "跳過一列資料，由於欄數不符: ";
                print_r($row);
            }
        }

        $SUSS_MAG = "成功輸入 " . $successCount . " 筆資料\n" . $SUSS_MAG;
        echo nl2br($SUSS_MAG); // 輸出訊息並換行

        // URL 編碼訊息以利傳遞
        $encodedMessage = urlencode($SUSS_MAG);
        header("location:Upload_List.php?message=oth&msg=" . $encodedMessage);
    } else {
        echo "請上傳一個 Excel 檔案";
    }
};

// 移轉紀錄 (新增 bom_ing) 2025.02.21 OK

// 寫入mysql邏輯
// 新增：當 bom_ing_id 是新的。
// 覆蓋/更新：當 bom_ing_id 已存在時，大部分欄位會被 Excel 的值覆蓋。但 outsource_date 的更新是有條件的，取決於 processing_state 是否為 'ING'。
// 不更新（內容無變化）：當 bom_ing_id 已存在，但 Excel 提供的資料與資料庫現有資料相同時，記錄內容不會改變。特別注意，若 processing_state 非 'ING'，outsource_date 欄位會主動維持資料庫原值，不採用 Excel 的值。

if ($_GET['but'] == 'u5') {

    ini_set('memory_limit', '512M'); // 提高記憶體限制
    $consoleLogs = []; // 初始化日誌陣列於流程開始處
    $consoleLogs[] = "開始處理 u5 (移轉紀錄) 上傳流程...";
    $msg = ""; // 初始化訊息變數

    function quoteValue($value)
    {
        return (is_null($value) || trim($value) === '' || strtoupper(trim($value)) === 'NULL') ? null : trim($value);
    }

    // 更穩健的日期轉換函數
    function convertDateFormat($date)
    {
        if (empty($date)) {
            return null;
        }
        // 首先處理數字格式的 Excel 日期
        if (is_numeric($date)) {
            // Check if it's a valid Excel timestamp
            if ($date > 25569) { // 25569 is 1/1/1970 in Excel
                try {
                    return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($date)
                        ->format('Y-m-d H:i:s');
                } catch (Exception $e) {
                    // 若 PhpSpreadsheet 解析失敗，則繼續嘗試字串解析
                }
            }
        }

        // 嘗試解析常見的字串格式
        $formatsToTry = [
            'Y/n/j g:i:s A',  // Matches '2025/5/12 12:00:00 AM' or '2025/05/12 1:23:45 PM'
            'Y/n/j H:i',      // Matches '2025/5/12 00:00' (Year/MonthNoLead/DayNoLead Hour24:Minute)
            'Y/m/d H:i:s',    // Matches '2025/05/12 00:00:00'
            'Y-m-d H:i:s',    // Common database format
            'n/j/Y g:i:s A',
            'm/d/Y H:i:s',
            'Y-m-d',
            'Y/m/d',
            'n/j/Y',
            'm/d/Y'
        ];

        foreach ($formatsToTry as $format) {
            $dateTime = DateTime::createFromFormat($format, trim($date));
            if ($dateTime) {
                // 如果格式僅為日期，則將時間設為 00:00:00
                if (strpos($format, 'H') === false && strpos($format, 'g') === false && strpos($format, 'h') === false) {
                    $dateTime->setTime(0, 0, 0);
                }
                return $dateTime->format('Y-m-d H:i:s');
            }
        }
        return null; // 若無格式符合
    }

    try {
        if (isset($_FILES['file']['name']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['file']['tmp_name'];
            $consoleLogs[] = "準備載入檔案: " . htmlspecialchars($_FILES['file']['name']);
            $spreadsheet = IOFactory::load($file);
            $sheet = $spreadsheet->getActiveSheet();
            $excel_data_array = $sheet->toArray();
            $consoleLogs[] = "Excel 檔案載入成功。總行數 (含標頭): " . count($excel_data_array);

            $data = array_slice($excel_data_array, 1); // 跳過標頭列
            $consoleLogs[] = "實際處理資料筆數 (不含標頭): " . count($data);

            if (count($data) == 0) {
                $consoleLogs[] = "警告: Excel 檔案中沒有資料可處理 (已排除標頭)。";
            }

            $insertStmt = $db->prepare("
                INSERT INTO bom_ing (
                    -- bom_ing_fid 會自動產生，不需在此列出
                    bom_ing_id, bom, machine_id, process_no,
                    maker_id_no, maker_id, sqty, bom_sn, processing_sequence,
                    processing_state, QC_check, QC_check_date, QC_ps, ps,
                    outsource_date, return_date, Delivery_date, PS2, 1_side,
                    Created_By, Created_At, Modified_By, Modified_At
                ) VALUES (
                    -- 對應下方23個問號
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");

            $updateStmt = $db->prepare("
                UPDATE bom_ing SET
                    bom = ?, machine_id = ?, process_no = ?,
                    maker_id_no = ?, maker_id = ?, sqty = ?, bom_sn = ?, processing_sequence = ?,
                    processing_state = ?, QC_check = ?, QC_check_date = ?, /* QC_ps removed */ ps = ?,
                    outsource_date = ?, return_date = ?, Delivery_date = ?, PS2 = ?, 1_side = ?,
                    Created_By = ?, Created_At = ?, Modified_By = ?, Modified_At = ?
                WHERE bom_ing_id = ?
            "); // QC_ps is removed from SET clause, ps and PS2 remain.

            // ✅ 修改：準備新的檢查語句，以 (bom, bom_sn) 為唯一鍵
            $checkStmt = $db->prepare("SELECT bom_ing_id FROM bom_ing WHERE bom = ? AND bom_sn = ?");

            $inserted = 0;
            $updated  = 0;
            // $consoleLogs 已在 try 區塊外初始化
            $firstWriteLogged = false; // 修改旗標名稱，用於追蹤第一筆寫入操作 (INSERT 或 UPDATE)

            foreach ($data as $row_index => $row) { // 加入 $row_index 以便記錄
                if (count($row) > 23) {
                    $row = array_slice($row, 0, 23);
                }


                // Apply date conversion to all relevant date columns
                // Excel columns (0-indexed) and their corresponding $row index:
                // QC_check_date: $row[11]
                // outsource_date: $row[14]
                // return_date: $row[15]
                // Delivery_date: $row[16]
                // Created_At: $row[20]
                // Modified_At: $row[22]
                $date_columns_indices = [11, 14, 15, 16, 20, 22];
                foreach ($date_columns_indices as $idx) {
                    if (isset($row[$idx])) {
                        $originalDate = $row[$idx];
                        $convertedDate = convertDateFormat($originalDate); // Convert
                        $row[$idx] = $convertedDate; // Assign back
                    } else {
                        $row[$idx] = null;
                    }
                }

                $row = array_map('quoteValue', $row);
                if (count($row) != 23) {
                    continue;
                }

                // ✅ 修改：從 Excel 行中取得 bom 和 bom_sn
                $bom_from_excel = $row[1];
                $bom_sn_from_excel = $row[7];

                // ✅ 修改：執行新的檢查
                $checkStmt->execute([$bom_from_excel, $bom_sn_from_excel]);
                $existing_record = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if ($existing_record) { // ✅ 修改：如果記錄存在
                    $bom_ing_id_for_update = $existing_record['bom_ing_id']; // ✅ 修改：使用從資料庫中找到的 bom_ing_id 來更新
                    $excel_processing_state_val = $row[9]; // 從 Excel 取得的 processing_state (經過 quoteValue 處理)

                    // 準備更新語句的參數
                    // 若資料列存在，這些值將從 Excel 更新
                    $updateDataFromExcel = array_slice($row, 1); // 對應 Excel 欄位 1-22，用於 SET 子句
                    // $updateDataFromExcel[8] 是 processing_state
                    // $updateDataFromExcel[13] 是 outsource_date (從 Excel 轉換而來)

                    if (strtoupper(trim((string)$excel_processing_state_val)) !== 'ING') {
                        // 如果 Excel 中的 processing_state 不是 'ING'，
                        // 則 outsource_date 不應從 Excel 更新。
                        // 我們需要使用資料庫中目前的 outsource_date 值。
                        $fetchOldDateStmt = $db->prepare("SELECT outsource_date FROM bom_ing WHERE bom_ing_id = ?"); // ✅ 修改：使用正確的 ID
                        $fetchOldDateStmt->execute([$bom_ing_id_for_update]);
                        $oldDbData = $fetchOldDateStmt->fetch(PDO::FETCH_ASSOC);

                        if ($oldDbData) {
                            // 將參數中的 Excel outsource_date 替換為資料庫中的值
                            $updateDataFromExcel[13] = $oldDbData['outsource_date'];
                        } else {
                            // 備用方案：若找不到 bom_ing_id (理論上 $exists 為 true 時不應發生)
                            // 或資料庫中的 outsource_date 為 NULL。
                            $updateDataFromExcel[13] = null;
                        }
                    }
                    // If processing_state IS 'ING', $updateDataFromExcel[13] (which is from Excel) is used.

                    // Construct the parameters for the execute call, skipping QC_ps ($updateDataFromExcel[11])
                    $paramsForSetClause = [];
                    // bom ($row[1]) to QC_check_date ($row[11]) -> indices 0 to 10 in $updateDataFromExcel
                    for ($i = 0; $i <= 10; $i++) {
                        $paramsForSetClause[] = $updateDataFromExcel[$i];
                    }
                    // ps ($row[13]) to Modified_At ($row[22]) -> indices 12 to 21 in $updateDataFromExcel
                    // This loop includes ps, outsource_date, return_date, Delivery_date, PS2, 1_side, Created_By, Created_At, Modified_By, Modified_At
                    for ($i = 12; $i <= 21; $i++) {
                        $paramsForSetClause[] = $updateDataFromExcel[$i];
                    }

                    $finalUpdateParams = $paramsForSetClause; // Should be 21 elements for SET clause
                    // (11 from first loop + 10 from second loop)
                    $finalUpdateParams[] = $bom_ing_id_for_update; // ✅ 修改：使用正確的 ID

                    if ($updateStmt->execute($finalUpdateParams)) {
                        // 記錄第一筆 UPDATE SQL 及其參數 (如果尚未記錄過任何寫入操作)
                        if (!$firstWriteLogged) {
                            $consoleLogs[] = "First UPDATE SQL: " . $updateStmt->queryString;
                            $consoleLogs[] = "First UPDATE Params: " . json_encode($finalUpdateParams);
                            $firstWriteLogged = true;
                        }
                        $affected_rows = $updateStmt->rowCount();
                        if ($affected_rows > 0) {
                            $updated++;
                        }
                    }
                } else {
                    // 記錄第一筆 INSERT SQL 及其參數
                    if (!$firstWriteLogged) {
                        $consoleLogs[] = "First INSERT SQL: " . $insertStmt->queryString;
                        $consoleLogs[] = "First INSERT Params: " . json_encode($row);
                        $firstWriteLogged = true;
                    }
                    if ($insertStmt->execute($row)) {
                        $inserted++;
                    }
                }
            }

            $msg = "成功新增 $inserted 筆、更新 $updated 筆資料";
            $consoleLogs[] = "處理完成。結果: " . $msg;
            $encodedMessage = urlencode($msg);
            recordUploadLog($db, 'upload_bom_ing_s');
            echo "<script>";
            // 直接跳轉，不再顯示日誌頁面
            echo "window.location.href = 'Upload_List.php?message=oth&msg=" . $encodedMessage . "';";
            echo "</script>";
            ob_end_flush(); // 送出輸出緩衝區內容
            exit;
        } else {
            $errorMsg = '請上傳一個 Excel 檔案。';
            if (isset($_FILES['file']['name']) && $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $errorMsg = '檔案上傳失敗，錯誤碼: ' . $_FILES['file']['error'];
            }
            $consoleLogs[] = "錯誤: " . $errorMsg;
            $msg = $errorMsg; // 設定 $msg 以便在HTML中顯示
            echo "<script>";
            echo "window.location.href = 'Upload_List.php?message=oth&msg=" . urlencode($msg) . "';";
            echo "</script>";
            ob_end_flush(); // 送出輸出緩衝區內容
            exit;
        }
    } catch (Exception $e) {
        $errorMsg = "處理檔案時發生嚴重錯誤：" . $e->getMessage(); // 處理檔案時發生嚴重錯誤
        echo "<script>";
        // echo "console.error(" . json_encode($errorMsg) . ");";
        // echo "console.error('Stack Trace:', " . json_encode($e->getTraceAsString()) . ");";
        echo "window.location.href = 'Upload_List.php?message=oth&msg=" . urlencode("處理檔案時發生嚴重錯誤，請檢查主機日誌。") . "';";
        echo "</script>";
        ob_end_flush(); // Send the output
        exit;
    }
}


// 新 BOM(製程 N-BOM_ING_ok) 2025.02.21 OK
if ($_GET['but'] == 'u5_NEW') {

    function quoteValue($value)
    {
        return (is_null($value) || $value === '' || strtoupper($value) === 'NULL') ? null : $value;
    }

    function convertDateFormat($date)
    {
        if (empty($date)) {
            return null;
        }
        // 首先處理數字格式的 Excel 日期
        if (is_numeric($date)) {
            // Check if it's a valid Excel timestamp (avoids treating simple numbers as dates)
            // Excel timestamps are typically large numbers (days since 1900 or 1904).
            // A loose check: greater than 25569 (for 1/1/1970)
            if ($date > 25569) {
                try {
                    return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($date) // 使用 PhpSpreadsheet 轉換 Excel 日期
                        ->format('Y-m-d H:i:s');
                } catch (Exception $e) {
                    // Fall through to string parsing if PhpSpreadsheet fails
                }
            }
        }

        // 嘗試解析常見的字串格式
        $formatsToTry = [
            'Y/n/j g:i:s A',  // Matches '2025/5/12 12:00:00 AM'
            'Y/m/d H:i:s',    // Matches '2025/05/12 00:00:00'
            'Y-m-d H:i:s',    // Common database format
            'n/j/Y g:i:s A',
            'm/d/Y H:i:s',
            'Y-m-d',
            'Y/m/d',
            'Y/n/j',          // Added to handle yyyy/m/d (e.g., 2024/5/7)
            'n/j/Y',
            'm/d/Y'
        ];

        foreach ($formatsToTry as $format) {
            $dateTime = DateTime::createFromFormat($format, $date);
            if ($dateTime) {
                // 如果格式僅為日期，則將時間設為 00:00:00
                if (strpos($format, 'H') === false && strpos($format, 'g') === false && strpos($format, 'h') === false) {
                    $dateTime->setTime(0, 0, 0);
                }
                return $dateTime->format('Y-m-d H:i:s');
            }
        }
        return null; // 若無格式符合
    }

    try {
        if (isset($_FILES['file']['name'])) {
            $file = $_FILES['file']['tmp_name'];
            $spreadsheet = IOFactory::load($file);
            $sheet = $spreadsheet->getActiveSheet();
            $data = $sheet->toArray();
            $data = array_slice($data, 1); // 移除標題列

            $stmt = $db->prepare("
                INSERT INTO bom_ing (
                    bom_ing_id, bom, machine_id, process_no, 
                    maker_id_no, maker_id, sqty, bom_sn, processing_sequence, 
                    processing_state, QC_check, QC_check_date, QC_ps, ps, 
                    outsource_date, return_date, Delivery_date, PS2, 1_side, 
                    Created_By, Created_At, Modified_By, Modified_At
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
                ON DUPLICATE KEY UPDATE
                    bom = VALUES(bom),
                    machine_id = VALUES(machine_id),
                    process_no = VALUES(process_no),
                    maker_id_no = VALUES(maker_id_no),
                    maker_id = VALUES(maker_id),
                    sqty = VALUES(sqty),
                    bom_sn = VALUES(bom_sn),
                    processing_sequence = VALUES(processing_sequence),
                    processing_state = VALUES(processing_state),
                    QC_check = VALUES(QC_check),
                    QC_check_date = VALUES(QC_check_date),
                    QC_ps = VALUES(QC_ps),
                    ps = VALUES(ps),
                    outsource_date = CASE WHEN CAST(VALUES(process_no) AS UNSIGNED) <> 138 THEN NULL ELSE VALUES(outsource_date) END,
                    return_date = CASE WHEN CAST(VALUES(process_no) AS UNSIGNED) <> 138 THEN NULL ELSE VALUES(outsource_date) END,
                    Delivery_date = VALUES(Delivery_date),
                    PS2 = VALUES(PS2),
                    1_side = VALUES(1_side),
                    Created_By = VALUES(Created_By),
                    Created_At = VALUES(Created_At),
                    Modified_By = VALUES(Modified_By),
                    Modified_At = VALUES(Modified_At)
            ");

            // 修正：檢查邏輯應基於 (bom, bom_sn) 的組合，而不是 bom_ing_id
            $checkStmt = $db->prepare("SELECT bom_ing_id FROM bom_ing WHERE bom = ? AND bom_sn = ?");

            // 修正：準備一個獨立的 UPDATE 語句，當記錄存在時使用
            $updateStmt = $db->prepare("
                UPDATE bom_ing SET
                    bom_ing_id = ?, bom = ?, machine_id = ?, process_no = ?, 
                    maker_id_no = ?, maker_id = ?, sqty = ?, bom_sn = ?, processing_sequence = ?, 
                    processing_state = ?, QC_check = ?, QC_check_date = ?, QC_ps = ?, ps = ?, 
                    outsource_date = ?, return_date = ?, Delivery_date = ?, PS2 = ?, 1_side = ?, 
                    Created_By = ?, Created_At = ?, Modified_By = ?, Modified_At = ?
                WHERE bom_ing_id = ? 
            ");

            $insertCount = 0;
            $updateCount = 0;

foreach ($data as $row) {
    if (count($row) > 23) {
        $row = array_slice($row, 0, 23);
    }

    // 修正：處理所有日期欄位
    // 索引對應：11:QC_check_date, 14:outsource_date, 15:return_date, 16:Delivery_date, 20:Created_At, 22:Modified_At
    $date_columns_indices = [11, 14, 15, 16, 20, 22]; 
    foreach ($date_columns_indices as $idx) {
        if (isset($row[$idx]) && !empty($row[$idx])) {
            $row[$idx] = convertDateFormat($row[$idx]);
        } else {
            // 僅在 Created_At 和 Modified_At 為空時填入當前時間
            if ($idx === 20 || $idx === 22) {
                $row[$idx] = date('Y-m-d H:i:s');
            } else {
                $row[$idx] = null; // 其他日期欄位若為空，則設為 null
            }
        }
    }
    // 確保 Created_At 和 Modified_At 總是有值
    if (empty($row[20])) { $row[20] = date('Y-m-d H:i:s'); }
    if (empty($row[22])) { $row[22] = date('Y-m-d H:i:s'); }


    $row = array_map(function ($v) {
        return $v === '' ? null : $v;
    }, $row);

    $quotedRow = array_map('quoteValue', $row);

    if (count($quotedRow) === 23) {
        $bom_from_excel = $quotedRow[1];
        $bom_sn_from_excel = $quotedRow[7];

        $checkStmt->execute([$bom_from_excel, $bom_sn_from_excel]);
        $existing_record = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing_record) {
            $bom_ing_id_for_update = $existing_record['bom_ing_id'];
            $updateParams = $quotedRow;
            $updateParams[] = $bom_ing_id_for_update;
            $updateStmt->execute($updateParams);
            $updateCount++;
        } else {
            $stmt->execute($quotedRow);
            $insertCount++;
        }
    }
}


            $msg = "共新增 {$insertCount} 筆，更新 {$updateCount} 筆";
            recordUploadLog($db, 'upload_bom_ing_new');
            header("location:Upload_List.php?message=oth&msg=" . urlencode($msg)); // 重新導向並帶上訊息
            exit;
        } else {
            header("location:Upload_List.php?message=oth&msg=" . urlencode("請上傳一個 Excel 檔案"));
            exit;
        }
    } catch (Exception $e) {
        header("location:Upload_List.php?message=oth&msg=" . urlencode("處理檔案時發生錯誤：" . $e->getMessage())); // 處理檔案時發生錯誤
        exit; // 確保在 header 後終止腳本
    }
}

// 新bom(新增bom)
if ($_GET['but'] == 'nb') {

    function quoteValue($value)
    {
        if (is_null($value) || $value === '' || strtoupper($value) === 'NULL') {
            return null;
        } else {
            return $value;
        }
    }

    function convertDateFormat($date)
    {
        echo "原始日期值：$date<br>";
        $dateTime = DateTime::createFromFormat('Y/m/d H:i', $date);
        if ($dateTime) {
            return $dateTime->format('Y/m/d H:i:s');
        } else {
            return null;
        }
    }

    if (isset($_FILES['file']['name'])) {
        $file = $_FILES['file']['tmp_name'];
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray();

        $data = array_slice($data, 1); // 跳過標頭列

        $insertStmt = $db->prepare("INSERT INTO `bom`
            (`bom`, `bom_ing_id`, `d_id`, `specification`, `sqty`,
             `Client_Name`, `state`, `o_order_id`, `processing_state`, `Created_By`,
             `Created_At`, `Modified_By`, `Modified_At`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $updateStmt = $db->prepare("UPDATE `bom` SET
            `bom_ing_id` = ?, `d_id` = ?, `specification` = ?, `sqty` = ?,
            `Client_Name` = ?, `state` = ?, `o_order_id` = ?, `processing_state` = ?,
            `Created_By` = ?, `Created_At` = ?, `Modified_By` = ?, `Modified_At` = ?
            WHERE `bom` = ?");

        $checkStmt = $db->prepare("SELECT COUNT(*) FROM bom WHERE bom = ?");

        $SUSS_MAG = "";
        $successInsert = 0;
        $successUpdate = 0;

        foreach ($data as $row) {
            if (empty(array_filter($row))) continue; // 跳過空行

            if (count($row) > 13) $row = array_slice($row, 0, 13);

            // 日期轉換 (若有)
            $row[10] = (!empty($row[10])) ? convertDateFormat($row[10]) : null;
            $row[12] = (!empty($row[12])) ? convertDateFormat($row[12]) : null;
            // 除錯用：顯示轉換後的日期值
            echo "轉換後的日期值：Row[10] = " . $row[10] . ", Row[12] = " . $row[12] . "<br>";

            // 空字串轉 null
            $row = array_map(function ($value) {
                return $value === '' ? null : $value;
            }, $row);

            $quotedRow = array_map('quoteValue', $row);

            if (count($quotedRow) == 13) {
                echo "資料內容: ";
                print_r($quotedRow);
                echo "<br>";

                // 檢查是否存在（以主鍵 bom 為準）
                $checkStmt->execute([$row[0]]);
                if ($checkStmt->fetchColumn() > 0) {
                    // 資料存在，更新
                    $updateData = array_slice($quotedRow, 1); // 移除 bom 欄位 (因為它在 WHERE 子句中)
                    $updateData[] = $quotedRow[0]; // 最後加上 WHERE bom = ? 的值
                    if ($updateStmt->execute($updateData)) {
                        $successUpdate++;
                    } else {
                        echo "更新失敗: " . $updateStmt->errorInfo()[2] . "<br>"; // 顯示更新錯誤訊息
                    }
                } else {
                    // 資料不存在，新增
                    if ($insertStmt->execute($quotedRow)) {
                        $successInsert++;
                    } else {
                        echo "插入失敗: " . $insertStmt->errorInfo()[2] . "<br>"; // 顯示插入錯誤訊息
                    }
                }
            } else {
                echo "錯誤：資料列數不符，預期 13 欄，實際 " . count($quotedRow) . " 欄。<br>";
                echo "跳過一列資料，由於欄數不符: ";
                print_r($row);
            }
        }
        // 總結訊息
        $SUSS_MAG = "成功新增 $successInsert 筆，更新 $successUpdate 筆資料。";
        echo nl2br($SUSS_MAG);

        recordUploadLog($db, 'upload_bom_nb');
        $encodedMessage = urlencode($SUSS_MAG);
        header("Location: Upload_List.php?message=oth&msg=" . $encodedMessage);
    } else {
        echo "請上傳一個 Excel 檔案";
    }
}


// 更新 已結案 OK_TMP
if ($_GET['but'] == 'nb_ok') {

    $userId = $_SESSION['id'] ?? null;

    if (isset($_FILES['file']['name']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['file']['tmp_name'];

        try {
            $spreadsheet = IOFactory::load($file);
            $sheet = $spreadsheet->getActiveSheet();
            $data = $sheet->toArray();

            // 確認 C1 (index [0][2]) 是否為 "製令單號"
            if (!isset($data[0][2]) || trim($data[0][2]) !== '製令單號') {
                $_SESSION['upload_message'] = "檔案格式錯誤：C1 必須為「製令單號」，實際值為「" . ($data[0][2] ?? '(空白)') . "」";
                header("Location: Upload_List.php?message=oth");
                exit;
            }

            // 移除標頭列，從第 2 列 (C2) 開始處理
            array_shift($data);

            $selectStmt = $db->prepare("SELECT processing_state FROM bom WHERE bom = ?");
            $updateStmt = $db->prepare("UPDATE bom SET processing_state = '1', Modified_At = NOW(), Modified_By = ? WHERE bom = ?");

            $successCount = 0;
            $noChangeCount = 0;
            $failCount    = 0;
            $skipCount    = 0;
            $noChangeBOMs = [];

            foreach ($data as $row) {
                if (empty(array_filter($row))) continue; // 跳過空行

                // S 欄 (index 18)：不分大小寫，必須為 "Y" 才處理，否則跳過
                $sValue = strtoupper(trim($row[18] ?? ''));
                if ($sValue !== 'Y') {
                    $skipCount++;
                    continue;
                }

                // C 欄 (index 2) = 製令單號 / BOM 號碼
                $bomValue = trim($row[2] ?? '');
                if ($bomValue === '') {
                    $failCount++;
                    continue;
                }

                $selectStmt->execute([$bomValue]);
                $result = $selectStmt->fetch(PDO::FETCH_ASSOC);

                if ($result) {
                    if ($result['processing_state'] == "1") {
                        $noChangeCount++;
                        $noChangeBOMs[] = $bomValue;
                    } else {
                        if ($updateStmt->execute([$userId, $bomValue])) {
                            $successCount++;
                        } else {
                            $failCount++;
                        }
                    }
                } else {
                    $failCount++;
                }
            }

            $summary = "成功更新 {$successCount} 筆，{$noChangeCount} 筆已結案未變更，跳過 {$skipCount} 筆（S欄非Y）";
            if ($failCount > 0) {
                $summary .= "\nBOM 不存在 {$failCount} 筆";
            }

            recordUploadLog($db, 'upload_bom_nb_ok');
            $_SESSION['upload_message'] = $summary;
            header("Location: Upload_List.php?message=oth");
            exit;

        } catch (Exception $e) {
            $_SESSION['upload_message'] = "處理檔案時發生錯誤：" . $e->getMessage();
            header("Location: Upload_List.php?message=oth");
            exit;
        }
    } else {
        $_SESSION['upload_message'] = '請上傳檔案！';
        header("Location: Upload_List.php?message=oth");
        exit;
    }
}
// Helper function to convert date formats to Y-m-d for DATE SQL type
function convertDateFormatToDate($date)
{
    if (empty($date) || strtoupper(trim($date)) === 'NULL') {
        return null;
    }
    if (is_numeric($date)) { // Excel numeric date
        // Check if it's a valid Excel timestamp (avoids treating simple numbers as dates)
        if ($date > 1) { // Excel dates are typically > 1 (e.g., 1 for 1/1/1900 or 1/1/1904)
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($date)
                    ->format('Y-m-d');
            } catch (Exception $e) {
                // Fall through if PhpSpreadsheet fails
            }
        }
    }
    // Attempt to parse common string formats
    $formatsToTry = [
        'Y/n/j',
        'Y/m/d',
        'Y-m-d',
        'n/j/Y',
        'm/d/Y',
        // With time, extract date part
        'Y/n/j g:i:s A',
        'Y/n/j G:i:s',
        'Y/n/j H:i:s',
        'Y/m/d H:i:s',
        'Y-m-d H:i:s',
        'n/j/Y g:i:s A',
        'n/j/Y G:i:s',
        'n/j/Y H:i:s',
        'm/d/Y H:i:s',
    ];

    foreach ($formatsToTry as $format) {
        $dateTime = DateTime::createFromFormat($format, trim($date));
        if ($dateTime) {
            return $dateTime->format('Y-m-d');
        }
    }
    // Fallback for simple date strings that might be parsable by strtotime
    if (strtotime(trim($date)) !== false) {
        return date('Y-m-d', strtotime(trim($date)));
    } else {
        return null; // 若無格式符合或日期無效，則回傳 null
    }
}


if (isset($_GET['but']) && $_GET['but'] === 'IS_List') {
    // --- 輔助函式，僅在本區塊定義 ---
    if (!function_exists('quoteValue')) {
        function quoteValue($value)
        {
            return ($value === '' || $value === null) ? null : $value;
        }
    }
    if (!function_exists('safeInt')) {
        function safeInt($value)
        {
            if ($value === '' || $value === null || !is_numeric($value)) {
                return null;
            }
            $int = (int)$value;
            if ($int > 2147483647 || $int < -2147483648) {
                return null;
            }
            return $int;
        }
    }
    if (!function_exists('convertDateFormatToDate')) {
        function convertDateFormatToDate($value)
        {
            if ($value === '' || $value === null) {
                return null;
            }
            // 1) Excel 序列化日期（純數字）
            if (is_numeric($value)) {
                try {
                    $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value);
                    return $dt->format('Y-m-d');
                } catch (Exception $e) {
                    // fall through to 下方嘗試字串解析
                }
            }
            // 2) 文字格式：月/日/年（例如 1/2/2025）
            if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $value)) {
                $dt = DateTime::createFromFormat('n/j/Y', $value);
                return $dt ? $dt->format('Y-m-d') : null;
            }
            // 3) 其它字串（最後再交給 strtotime）
            $ts = strtotime($value);
            return $ts ? date('Y-m-d', $ts) : null;
        }
    }


    $SUSS_MAG = "";
    $consoleLogs = [];
    $userId = $_SESSION['id'] ?? 'excel_import_user';

    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['file']['tmp_name'];
        try {
            $spreadsheet = IOFactory::load($file);
            $sheet = $spreadsheet->getActiveSheet();
            $excelData = $sheet->toArray();

            if (count($excelData) < 2) {
                throw new Exception("Excel 檔案為空或僅有標頭。");
            }

            $header = array_shift($excelData);
            $db->exec("CREATE TEMPORARY TABLE IF NOT EXISTS temp_is_list (
                Order_date DATE,
                IS_number VARCHAR(20),
                Client_id VARCHAR(20),
                Client_name VARCHAR(50),
                Product_id VARCHAR(30),
                Specification VARCHAR(255),
                Qty INT,
                Unit_price DECIMAL(10,2),
                Order_id INT,
                Warehouse VARCHAR(30),
                Note VARCHAR(100),
                temp_Created_By VARCHAR(11),
                temp_Created_At TIMESTAMP
            )");
            $db->exec("TRUNCATE TABLE temp_is_list");

            $db->beginTransaction();

            $tempInsertSql = "INSERT INTO temp_is_list (
                Order_date, IS_number, Client_id, Client_name, Product_id, Specification,
                Qty, Unit_price, Order_id, Warehouse, Note, temp_Created_By, temp_Created_At
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $tempInsertStmt = $db->prepare($tempInsertSql);

            foreach ($excelData as $rowIndex => $row) {
                if (empty(array_filter($row))) continue;
                // 假設你已經做了： $paddedRow = array_pad($row, 9, null);
                $paddedRow = array_pad($row, 9, null);

                // 1) 日期：月/日/年 格式
                $order_date   = convertDateFormatToDate(quoteValue($paddedRow[0]));

                // 2) 必填欄位：IS_number、Client_name
                $is_number    = quoteValue($paddedRow[1]);
                $client_name  = quoteValue($paddedRow[2]);

                // 3) 其他欄位
                $warehouse    = quoteValue($paddedRow[3]);
                $product_id   = quoteValue($paddedRow[4]);
                $specification = quoteValue($paddedRow[5]);
                $qty          = safeInt(quoteValue($paddedRow[6]));
                $unit_price = is_numeric($paddedRow[7]) ? (int)$paddedRow[7] : null;
                $note         = quoteValue($paddedRow[8]);

                // 4) Excel 沒有這兩欄，我們直接設 NULL
                $client_id    = null;
                $order_id     = null;

                // 5) 如果這兩個「絕對要有」都不存在，就跳過
                if (empty($is_number) || empty($client_name)) {
                    $consoleLogs[] = "跳過第 " . ($rowIndex + 1) . " 列：IS_number 或 Client_name 為空";
                    continue;
                }

                // 6) 把這些值塞到暫存表
                $tempInsertStmt->execute([
                    $order_date,    // Order_date
                    $is_number,     // IS_number
                    $client_id,     // Client_id
                    $client_name,   // Client_name
                    $product_id,    // Product_id
                    $specification, // Specification
                    $qty,           // Qty
                    $unit_price,    // Unit_price
                    $order_id,      // Order_id
                    $warehouse,     // Warehouse
                    $note,          // Note
                    $userId         // temp_Created_By
                ]);
            }


// 1) 關閉唯一索引檢查
$db->exec("SET unique_checks = 0");

// 2) 一次批次把暫存表的所有列插入正式表
$insSql = "
  INSERT INTO is_list (
    Order_date, IS_number, Client_id, Client_name, Product_id, Specification,
    Qty, Unit_price, Order_id, Warehouse, Note, Created_By, Created_At
  )
  SELECT
    Order_date, IS_number, Client_id, Client_name, Product_id, Specification,
    Qty, Unit_price, Order_id, Warehouse, Note, temp_Created_By, NOW()
  FROM temp_is_list
";
$insertCount = $db->exec($insSql);

// 3) 重新啟用索引檢查
$db->exec("SET unique_checks = 1");

// 4) 提交事務並顯示結果
$db->commit();
$SUSS_MAG = "共匯入 {$insertCount} 筆資料（不論重複）。";
$_SESSION['upload_message'] = $SUSS_MAG;
header("Location: Upload_List.php?message=oth");
exit;

            // 把統計訊息放到日誌
            // --- 結束：正式表同步邏輯 ---



        } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['upload_message'] = "資料庫操作失敗: " . $e->getMessage();
        header("Location: Upload_List.php?message=oth");
        exit;
    }
}}

// ══════════════════════════════════════════════════════════════════════════════
// ERP 出貨單匯入 (is_list) — 共用函式 + 三個入口
// ══════════════════════════════════════════════════════════════════════════════

// 解析 ERP 日期：民國年(YYY/MM/DD) + 西元年(YYYY/MM/DD) + Excel 序列值
if (!function_exists('parseERPDate_erp')) {
    function parseERPDate_erp($value) {
        if ($value === null || trim((string)$value) === '') return null;
        $v = trim((string)$value);
        if (is_numeric($v) && (float)$v > 25569) {
            try {
                $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$v);
                return $dt->format('Y-m-d');
            } catch (Exception $e) {}
        }
        if (preg_match('/^(\d+)\/(\d+)\/(\d+)$/', $v, $m)) {
            $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
            if ($y < 500) $y += 1911;
            return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v;
        return null;
    }
}

// 從數量字串取出數字（"95.0 PCS"、"3.0 批" → float）
if (!function_exists('parseERPQty_erp')) {
    function parseERPQty_erp($value) {
        if ($value === null) return null;
        $v = trim((string)$value);
        if ($v === '') return null;
        if (preg_match('/^([\d.]+)/', $v, $m)) return (float)$m[1];
        return null;
    }
}

// 解析整張工作表 → 回傳有效列陣列（carry-forward 帶值）
if (!function_exists('parseERPRowsFromSheet')) {
    function parseERPRowsFromSheet($allRows) {
        $validRows = [];
        $lastDate = $lastISNum = $lastClient = $lastWarehouse = null;
        foreach ($allRows as $row) {
            $r    = array_pad(array_values($row), 12, null);
            $colE = trim((string)($r[4] ?? ''));
            if ($colE === '' || in_array($colE, ['產品編號', '產品編號號', '品名規格'])) continue;
            $qty  = parseERPQty_erp(trim((string)($r[6] ?? '')));
            if ($qty === null) continue;

            $colA = trim((string)($r[0] ?? ''));
            $colB = trim((string)($r[1] ?? ''));
            $colC = trim((string)($r[2] ?? ''));
            $colD = trim((string)($r[3] ?? ''));
            $colF = trim((string)($r[5] ?? ''));
            $colH = trim((string)($r[7] ?? ''));
            $colK = trim((string)($r[10] ?? ''));

            if ($colB !== '') {
                $lastISNum     = $colB;
                $lastClient    = $colC;
                $lastWarehouse = $colD;
                if ($colA !== '') $lastDate = parseERPDate_erp($colA);
            }
            if ($lastISNum === null) continue;

            $n = str_replace(',', '', $colH);

            // 品名規格拆分（同退貨單邏輯）：空格左側 → Specification，右側 → Content
            // 若無空格，全部寫入 Content，Specification 為 NULL
            $isSpecLeft = null;
            $isContent  = null;
            if ($colF !== '') {
                $isSp = mb_strpos($colF, ' ');
                if ($isSp !== false) {
                    $isSpecLeft = mb_substr($colF, 0, $isSp);
                    $isContent  = mb_substr($colF, $isSp + 1);
                } else {
                    $isContent = $colF;
                }
            }

            $validRows[] = [
                'order_date'    => $lastDate,
                'is_number'     => $lastISNum,
                'client_name'   => mb_substr((string)$lastClient,    0, 50),
                'warehouse'     => mb_substr((string)$lastWarehouse,  0, 30),
                'product_id'    => mb_substr($colE, 0, 30),
                'd_setting_id'  => null,  // 由 Preview 步驟批次綁定
                'specification' => $isSpecLeft !== null ? mb_substr($isSpecLeft, 0, 80) : null,
                'content'       => $isContent  !== null ? mb_substr($isContent,  0, 100) : null,
                'qty'           => (int)$qty,
                'unit_price'    => is_numeric($n) ? (int)round((float)$n) : 0,
                'note'          => $colK !== '' ? mb_substr($colK, 0, 100) : null,
            ];
        }
        return $validRows;
    }
}

// 執行 DELETE + INSERT，回傳 ['deleted'=>N, 'inserted'=>M]
if (!function_exists('commitERPRows')) {
    function commitERPRows($db, $validRows, $earliestDate, $userId) {
        $db->beginTransaction();
        $del = $db->prepare("DELETE FROM is_list WHERE Order_date >= ?");
        $del->execute([$earliestDate]);
        $deletedRows = $del->rowCount();

        $ins = $db->prepare("INSERT INTO is_list
            (Order_date, IS_number, Client_id, Client_name, Product_id, d_setting_id, Specification, Content,
             Qty, Unit_price, Order_id, Warehouse, Note, Created_By, Created_At)
            VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, NOW())");
        $insertCount = 0;
        foreach ($validRows as $vr) {
            $ins->execute([
                $vr['order_date'], $vr['is_number'], $vr['client_name'],
                $vr['product_id'], $vr['d_setting_id'], $vr['specification'], $vr['content'], $vr['qty'],
                $vr['unit_price'], $vr['warehouse'], $vr['note'], $userId,
            ]);
            $insertCount++;
        }
        $db->commit();
        return ['deleted' => $deletedRows, 'inserted' => $insertCount];
    }
}

// ── 料號綁定共用函式 ─────────────────────────────────────────────────────────

// 批次查詢 client_name → customer_id（精確比對 customer_list.customer）
if (!function_exists('lookupCustomerIdsByName')) {
    function lookupCustomerIdsByName($db, array $clientNames) {
        $map = [];
        $stmt = $db->prepare("SELECT customer_id FROM customer_list WHERE customer = ? OR customer_full = ? LIMIT 1");
        foreach (array_unique($clientNames) as $name) {
            $name = trim($name);
            if ($name === '') continue;
            $stmt->execute([$name, $name]);
            $cid = $stmt->fetchColumn();
            if ($cid !== false) $map[$name] = $cid;
        }
        return $map;
    }
}

// 以 (D_Setting_Id + Customer_Id) 雙條件綁定 d_setting，更新 rows 的 d_setting_id / customer_id
// $productKey: row 中料號欄位名，$clientKey: 客戶名稱欄位名
if (!function_exists('bindDSettingIds')) {
    function bindDSettingIds($db, array &$rows, $productKey, $clientKey, array $customerMap) {
        $cache = [];
        $stmtWith    = $db->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id = ? AND Customer_Id = ? LIMIT 1");
        $stmtWithout = $db->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id = ? AND (Customer_Id IS NULL OR Customer_Id = '') LIMIT 1");
        foreach ($rows as &$row) {
            $pid = $row[$productKey] ?? '';
            $cid = $customerMap[trim($row[$clientKey] ?? '')] ?? null;
            $row['customer_id'] = $cid;
            $key = $pid . '|' . ($cid ?? '');
            if (!array_key_exists($key, $cache)) {
                if ($cid !== null) {
                    $stmtWith->execute([$pid, $cid]);
                    $dId = $stmtWith->fetchColumn();
                } else {
                    $stmtWithout->execute([$pid]);
                    $dId = $stmtWithout->fetchColumn();
                }
                $cache[$key] = $dId !== false ? (int)$dId : null;
            }
            $row['d_setting_id'] = $cache[$key];
        }
        unset($row);
    }
}

// 自動建立缺少 d_setting 的料號（在 Commit 時才執行，不在 Preview 執行）
// 回傳實際新增的 d_setting 筆數
if (!function_exists('autoCreateDSettings')) {
    function autoCreateDSettings($db, array &$rows, $productKey, $remark, $userId) {
        $created = [];
        $chkWith    = $db->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id = ? AND Customer_Id = ? LIMIT 1");
        $chkWithout = $db->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id = ? AND (Customer_Id IS NULL OR Customer_Id = '') LIMIT 1");
        $ins = $db->prepare("INSERT INTO d_setting (D_Setting_Id, Customer_Id, Remark, Created_By) VALUES (?, ?, ?, ?)");
        foreach ($rows as &$row) {
            if ($row['d_setting_id'] !== null) continue;
            $pid = $row[$productKey] ?? '';
            if ($pid === '') continue;
            $cid = $row['customer_id'] ?? null;
            $key = $pid . '|' . ($cid ?? '');
            if (isset($created[$key])) {
                $row['d_setting_id'] = $created[$key]; continue;
            }
            // 再次確認（避免 Preview→Commit 時間差）
            if ($cid !== null) { $chkWith->execute([$pid, $cid]); $exist = $chkWith->fetchColumn(); }
            else                { $chkWithout->execute([$pid]);    $exist = $chkWithout->fetchColumn(); }
            if ($exist !== false) {
                $row['d_setting_id'] = (int)$exist;
            } else {
                $ins->execute([$pid, $cid, $remark, $userId]);
                $row['d_setting_id'] = (int)$db->lastInsertId();
                $created[$key] = $row['d_setting_id'];
            }
        }
        unset($row);
        return count($created);
    }
}

// ── 入口①：直接匯入（備用，非 AJAX）────────────────────────────────────────
if (isset($_GET['but']) && $_GET['but'] === 'IS_List_ERP') {
    set_time_limit(300);
    ini_set('memory_limit', '512M');
    $userId = $_SESSION['id'] ?? 'excel_import';

    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        try {
            $spreadsheet = IOFactory::load($_FILES['file']['tmp_name']);
            $validRows   = parseERPRowsFromSheet($spreadsheet->getActiveSheet()->toArray());
            if (empty($validRows)) throw new Exception("未找到有效資料列，請確認檔案格式。");
            $dates = array_filter(array_column($validRows, 'order_date'));
            sort($dates);
            $earliestDate = $dates[0] ?? null;
            if (!$earliestDate) throw new Exception("無法解析日期，請確認格式（如 115/01/01）。");
            $result = commitERPRows($db, $validRows, $earliestDate, $userId);
            $_SESSION['upload_message'] =
                "ERP出貨單直接匯入完成。\n" .
                "最早出貨日期：{$earliestDate}\n" .
                "已清除 {$earliestDate} 起（含當日）的 {$result['deleted']} 筆舊資料\n" .
                "新匯入 {$result['inserted']} 筆資料";
            header("Location: Upload_List.php?message=oth"); exit;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['upload_message'] = "ERP匯入失敗：" . $e->getMessage();
            header("Location: Upload_List.php?message=oth"); exit;
        }
    } else {
        $_SESSION['upload_message'] = "請選擇要上傳的檔案。";
        header("Location: Upload_List.php?message=oth"); exit;
    }
}

// ── 入口②：AJAX 預覽 — 解析 + 驗證 + 暫存 Session（30分鐘）────────────────
if (isset($_GET['but']) && $_GET['but'] === 'IS_List_ERP_Preview') {
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(120);
    ini_set('memory_limit', '512M');

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => '請選擇要上傳的檔案']); exit;
    }

    try {
        $spreadsheet = IOFactory::load($_FILES['file']['tmp_name']);
        $allRows     = $spreadsheet->getActiveSheet()->toArray();

        // 1. 必要字樣掃描（前30行）— 阻擋性驗證
        $scannedText = '';
        foreach ($allRows as $i => $row) {
            if ($i >= 30) break;
            foreach ($row as $cell) { $scannedText .= (string)($cell ?? '') . '|'; }
        }
        $blockErrors = [];
        if (mb_strpos($scannedText, '銷貨單日報表') === false)
            $blockErrors[] = '找不到「銷貨單日報表」字樣，請確認是否為正確的 ERP 銷貨單日報表';
        if (mb_strpos($scannedText, '單據型別：日期別 明細表') === false)
            $blockErrors[] = '找不到「單據型別：日期別 明細表」字樣，請確認報表型別設定是否正確';
        if (!empty($blockErrors)) {
            echo json_encode(['success' => false, 'message' => '檔案格式驗證失敗', 'errors' => $blockErrors]); exit;
        }

        // 2. 欄位標題檢查
        $headerFound = false;
        foreach ($allRows as $i => $row) {
            if ($i >= 30) break;
            $r = array_pad(array_values($row), 8, null);
            if (mb_strpos((string)($r[0] ?? ''), '單據日期') !== false &&
                mb_strpos((string)($r[1] ?? ''), '單據號碼') !== false) {
                $headerFound = true; break;
            }
        }

        // 3. 解析有效列
        $validRows = parseERPRowsFromSheet($allRows);
        if (empty($validRows)) {
            echo json_encode(['success' => false, 'message' => '未找到有效資料列，請確認是否為 ERP 銷貨單日報表']); exit;
        }

        // 4. 出貨單號必須以 IS 開頭 — 阻擋性驗證
        $allISNums = array_unique(array_column($validRows, 'is_number'));
        $nonISNums = array_values(array_filter($allISNums, fn($n) => !preg_match('/^IS/', $n)));
        if (count($allISNums) > 0 && count($nonISNums) / count($allISNums) > 0.2) {
            $eg = implode('、', array_slice($nonISNums, 0, 3));
            echo json_encode([
                'success' => false,
                'message' => '出貨單號驗證失敗',
                'errors'  => ["出貨單號不符合 IS 開頭格式（共 " . count($nonISNums) . " 個不符，範例：{$eg}），請確認資料來源"],
            ]); exit;
        }

        // 5. 日期分析
        $dates = array_filter(array_column($validRows, 'order_date'));
        sort($dates);
        $earliestDate = $dates[0];
        $latestDate   = end($dates);

        // 6. 查詢 DB 將被清除的筆數
        $countStmt = $db->prepare("SELECT COUNT(*) FROM is_list WHERE Order_date >= ?");
        $countStmt->execute([$earliestDate]);
        $existingDeleteCount = (int)$countStmt->fetchColumn();

        // 7. 單價為0的比例
        $zeroPriceCnt = count(array_filter($validRows, fn($r) => $r['unit_price'] == 0));
        $zeroPricePct = count($validRows) > 0 ? $zeroPriceCnt / count($validRows) : 0;

        // 8. 料號綁定（含客戶驗證）：先查 customer_id，再用 D_Setting_Id + Customer_Id 雙條件比對
        $customerMap = lookupCustomerIdsByName($db, array_column($validRows, 'client_name'));
        bindDSettingIds($db, $validRows, 'product_id', 'client_name', $customerMap);
        $boundCount      = count(array_filter(array_column($validRows, 'd_setting_id')));
        $autoCreateCount = count($validRows) - $boundCount; // Preview 估算：不符合的列將在 Commit 時自動建立料號

        // 9. 前10筆預覽（DEBUG 用）
        $previewRows = array_slice($validRows, 0, 5);

        // 10. 彙整警告（非阻擋性）
        $warnings    = [];
        $today       = date('Y-m-d');
        $halfYearAgo = date('Y-m-d', strtotime('-6 months'));

        if (!$headerFound)
            $warnings[] = "未找到標準欄位標題（單據日期、單據號碼），請確認欄位順序";
        if ($earliestDate > $today)
            $warnings[] = "最早日期 {$earliestDate} 為未來日期，請確認資料是否正確";
        if ($earliestDate < $halfYearAgo)
            $warnings[] = "最早日期 {$earliestDate} 超過6個月前，將大量清除歷史資料（{$existingDeleteCount} 筆）";
        if ($zeroPricePct > 0.5)
            $warnings[] = "超過50%的單價為0（共 {$zeroPriceCnt} 筆），請確認單價欄位是否正確";

        // 11. 暫存至 Session
        $_SESSION['erp_import_rows']     = $validRows;
        $_SESSION['erp_import_earliest'] = $earliestDate;
        $_SESSION['erp_import_ts']       = time();

        echo json_encode([
            'success'               => true,
            'header_ok'             => $headerFound,
            'total_rows'            => count($validRows),
            'date_min'              => $earliestDate,
            'date_max'              => $latestDate,
            'existing_delete_count' => $existingDeleteCount,
            'is_numbers_sample'     => array_values(array_slice($allISNums, 0, 5)),
            'bound_count'        => $boundCount,
            'auto_create_count'  => $autoCreateCount,
            'warnings'           => $warnings,
            'preview_rows'       => $previewRows,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '解析失敗：' . $e->getMessage()]);
    }
    exit;
}

// ── 入口③：AJAX 確認匯入 — 讀 Session → 自動建立料號 → DELETE + INSERT ────
if (isset($_GET['but']) && $_GET['but'] === 'IS_List_ERP_Commit') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_SESSION['erp_import_rows']) || time() - (int)($_SESSION['erp_import_ts'] ?? 0) > 1800) {
        echo json_encode(['success' => false, 'message' => '預覽資料已過期（超過30分鐘），請重新上傳檔案']); exit;
    }

    $validRows    = $_SESSION['erp_import_rows'];
    $earliestDate = $_SESSION['erp_import_earliest'];
    $userId       = $_SESSION['id'] ?? 'excel_import';
    unset($_SESSION['erp_import_rows'], $_SESSION['erp_import_earliest'], $_SESSION['erp_import_ts']);

    try {
        // 自動建立在 Preview 時未找到對應的料號
        $newDCount = autoCreateDSettings($db, $validRows, 'product_id', '匯入出貨單自動建立', $userId);
        $result = commitERPRows($db, $validRows, $earliestDate, $userId);
        $msg = "匯入完成！清除 {$result['deleted']} 筆舊資料，新增 {$result['inserted']} 筆";
        if ($newDCount > 0) $msg .= "，自動建立 {$newDCount} 筆新料號";
        recordUploadLog($db, 'upload_is_erp');
        echo json_encode([
            'success'          => true,
            'deleted_rows'     => $result['deleted'],
            'inserted_rows'    => $result['inserted'],
            'new_d_count'      => $newDCount,
            'message'          => $msg,
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['success' => false, 'message' => '匯入失敗：' . $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// ERP 退貨單匯入 (ir_track) — 共用函式 + AJAX 預覽/確認
// 前置作業：需先在 phpMyAdmin 執行 ALTER TABLE（見部署說明）
// ══════════════════════════════════════════════════════════════════════════════

// 解析退貨單列 — 兩段掃描
// ERP 格式特殊：公司簡稱/倉庫在單據號碼的【下一行】，需先建 IR_no→(date,client,warehouse) 映射
if (!function_exists('parseIRRowsFromSheet')) {
    function parseIRRowsFromSheet($allRows) {

        // ── 第一段：建立 IR_no → 日期/客戶/倉庫 映射表 ──────────────────────
        $orderMap = [];   // [ ir_no => ['date'=>..., 'client'=>..., 'warehouse'=>...] ]
        $lastIRNo = null;
        foreach ($allRows as $row) {
            $r    = array_pad(array_values($row), 12, null);
            $colA = trim((string)($r[0] ?? ''));
            $colB = trim((string)($r[1] ?? ''));
            $colC = trim((string)($r[2] ?? ''));
            $colD = trim((string)($r[3] ?? ''));

            if ($colB !== '' && preg_match('/^IR\d+$/', $colB)) {
                // 新的退貨單起始行（有 IR_no）
                $lastIRNo = $colB;
                if (!isset($orderMap[$lastIRNo])) {
                    $orderMap[$lastIRNo] = ['date' => parseERPDate_erp($colA), 'client' => '', 'warehouse' => ''];
                }
                // 若這行同時有客戶資訊（部分ERP格式）
                if ($colC !== '') {
                    $orderMap[$lastIRNo]['client']    = $colC;
                    $orderMap[$lastIRNo]['warehouse'] = $colD;
                }
            } elseif ($lastIRNo !== null && $colC !== '' && $colB === '') {
                // 客戶行：B欄空、C欄有值 → 屬於上一個 IR_no 的客戶資訊
                $orderMap[$lastIRNo]['client']    = $colC;
                $orderMap[$lastIRNo]['warehouse'] = $colD;
            }
        }

        // ── 第二段：解析商品行，使用映射表帶入客戶資訊 ──────────────────────
        $validRows = [];
        $lastIRNo  = null;
        foreach ($allRows as $row) {
            $r    = array_pad(array_values($row), 12, null);
            $colE = trim((string)($r[4] ?? ''));

            // 沒有產品編號 → 跳過（標題行、合計行、客戶行、空白行）
            if ($colE === '' || in_array($colE, ['產品編號', '產品編號號', '品名規格'])) continue;
            $qty = parseERPQty_erp(trim((string)($r[6] ?? '')));
            if ($qty === null) continue;

            $colB = trim((string)($r[1] ?? ''));
            if ($colB !== '' && preg_match('/^IR\d+$/', $colB)) $lastIRNo = $colB;
            if ($lastIRNo === null || !isset($orderMap[$lastIRNo])) continue;

            $order = $orderMap[$lastIRNo];
            $colF  = trim((string)($r[5] ?? ''));
            $colH  = trim((string)($r[7] ?? ''));
            $colK  = trim((string)($r[10] ?? ''));
            $n     = str_replace(',', '', $colH);

            // 品名規格拆分：空格左側 → Specification（品名），空格右側 → IR_ps（退貨原因）
            // 若無空格，全部寫入 IR_ps，Specification 為 NULL
            // 注意：必須用 mb_strpos，避免中文字元造成 byte/字元位置不一致
            $specLeft = null;
            $irPsVal  = null;
            if ($colF !== '') {
                $sp = mb_strpos($colF, ' ');
                if ($sp !== false) {
                    $specLeft = mb_substr($colF, 0, $sp);
                    $irPsVal  = mb_substr($colF, $sp + 1);
                } else {
                    $irPsVal = $colF;
                }
            }

            $validRows[] = [
                'ir_date'       => $order['date'],
                'ir_no'         => mb_substr($lastIRNo, 0, 20),
                'client_name'   => mb_substr($order['client'], 0, 50),
                'warehouse'     => mb_substr($order['warehouse'], 0, 30),
                'd_id'          => mb_substr($colE, 0, 30),
                'd_setting_id'  => null,  // 由 Preview 步驟綁定
                'customer_id'   => null,  // 由 bindDSettingIds 填入
                'specification' => $specLeft !== null ? mb_substr($specLeft, 0, 80) : null,
                'ir_ps'         => $irPsVal  !== null ? mb_substr($irPsVal,  0, 300) : null,
                'qty'           => (int)$qty,
                'unit_price'    => is_numeric($n) ? (int)round((float)$n) : 0,
                'erp_note'      => $colK !== '' ? mb_substr($colK, 0, 100) : null,
            ];
        }
        return $validRows;
    }
}

// 遞迴刪除：由最深層子孫資料表往上刪，解決任意深度的 FK 鏈
if (!function_exists('cascadeDeleteByIds')) {
    function cascadeDeleteByIds($db, $tableName, $pkColumn, $ids) {
        if (empty($ids)) return;
        $inList = implode(',', array_map('intval', $ids));

        // 查詢所有直接子資料表（FK 指向此 table.pkColumn）
        $fkStmt = $db->prepare("
            SELECT TABLE_NAME, COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE REFERENCED_TABLE_NAME = ?
              AND REFERENCED_COLUMN_NAME = ?
              AND TABLE_SCHEMA = DATABASE()
        ");
        $fkStmt->execute([$tableName, $pkColumn]);

        foreach ($fkStmt->fetchAll(PDO::FETCH_ASSOC) as $ct) {
            $childTable = $ct['TABLE_NAME'];
            $childFKCol = $ct['COLUMN_NAME'];

            // 找出子資料表的 PK 欄位
            $pkStmt = $db->prepare("
                SELECT COLUMN_NAME FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                  AND COLUMN_KEY = 'PRI' LIMIT 1
            ");
            $pkStmt->execute([$childTable]);
            $childPK = $pkStmt->fetchColumn();

            if ($childPK) {
                // 取得待刪子記錄的 PK 值，遞迴處理孫子層
                $childIdRows = $db->query(
                    "SELECT `{$childPK}` FROM `{$childTable}` WHERE `{$childFKCol}` IN ({$inList})"
                );
                $childIds = $childIdRows->fetchAll(PDO::FETCH_COLUMN);
                cascadeDeleteByIds($db, $childTable, $childPK, $childIds);
            }

            // 刪除子記錄
            $db->exec("DELETE FROM `{$childTable}` WHERE `{$childFKCol}` IN ({$inList})");
        }
    }
}

// 執行退貨單 DELETE(cascade) + INSERT
if (!function_exists('commitIRRows')) {
    function commitIRRows($db, $validRows, $earliestDate, $userId) {
        $db->beginTransaction();

        // 找出將被清除的 IR_id，遞迴刪除所有子孫資料表記錄後再刪主表
        $findIds = $db->prepare("SELECT IR_id FROM ir_track WHERE IR_date >= ?");
        $findIds->execute([$earliestDate]);
        $idsToDelete = $findIds->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($idsToDelete)) {
            cascadeDeleteByIds($db, 'ir_track', 'IR_id', $idsToDelete);
        }

        $del = $db->prepare("DELETE FROM ir_track WHERE IR_date >= ?");
        $del->execute([$earliestDate]);
        $deletedRows = $del->rowCount();

        // INSERT（需先在 phpMyAdmin 執行 ALTER TABLE 新增欄位）
        $ins = $db->prepare("INSERT INTO ir_track
            (IR_date, IR_no, Client_name, Warehouse, d_id, d_setting_id, Specification,
             Qty, Unit_price, IR_ps, ERP_note, has_ncr, IR_status, Created_By, Created_At)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, NOW())");
        $insertCount = 0;
        foreach ($validRows as $vr) {
            $ins->execute([
                $vr['ir_date'],      $vr['ir_no'],        $vr['client_name'],
                $vr['warehouse'],    $vr['d_id'],          $vr['d_setting_id'],
                $vr['specification'],$vr['qty'],           $vr['unit_price'],
                $vr['ir_ps'],        $vr['erp_note'],      $userId,
            ]);
            $insertCount++;
        }
        $db->commit();
        return ['deleted' => $deletedRows, 'inserted' => $insertCount];
    }
}

// ── 退貨單 AJAX 預覽 ─────────────────────────────────────────────────────────
if (isset($_GET['but']) && $_GET['but'] === 'IR_List_ERP_Preview') {
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(120);
    ini_set('memory_limit', '512M');

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => '請選擇要上傳的檔案']); exit;
    }

    try {
        $spreadsheet = IOFactory::load($_FILES['file']['tmp_name']);
        $allRows     = $spreadsheet->getActiveSheet()->toArray();

        // 1. 必要字樣掃描 — 阻擋性驗證
        $scannedText = '';
        foreach ($allRows as $i => $row) {
            if ($i >= 30) break;
            foreach ($row as $cell) { $scannedText .= (string)($cell ?? '') . '|'; }
        }
        $blockErrors = [];
        if (mb_strpos($scannedText, '銷貨退回日報表') === false)
            $blockErrors[] = '找不到「銷貨退回日報表」字樣，請確認是否為正確的 ERP 銷貨退回日報表';
        if (mb_strpos($scannedText, '單據型別：日期別 明細表') === false)
            $blockErrors[] = '找不到「單據型別：日期別 明細表」字樣，請確認報表型別設定';
        if (!empty($blockErrors)) {
            echo json_encode(['success' => false, 'message' => '檔案格式驗證失敗', 'errors' => $blockErrors]); exit;
        }

        // 2. 欄位標題檢查
        $headerFound = false;
        foreach ($allRows as $i => $row) {
            if ($i >= 30) break;
            $r = array_pad(array_values($row), 8, null);
            if (mb_strpos((string)($r[0] ?? ''), '單據日期') !== false &&
                mb_strpos((string)($r[1] ?? ''), '單據號碼') !== false) {
                $headerFound = true; break;
            }
        }

        // 3. 解析有效列
        $validRows = parseIRRowsFromSheet($allRows);
        if (empty($validRows)) {
            echo json_encode(['success' => false, 'message' => '未找到有效資料列，請確認檔案格式']); exit;
        }

        // 4. 退貨單號必須以 IR 開頭 — 阻擋性驗證
        $allIRNums = array_unique(array_column($validRows, 'ir_no'));
        $nonIRNums = array_values(array_filter($allIRNums, fn($n) => !preg_match('/^IR/', $n)));
        if (count($allIRNums) > 0 && count($nonIRNums) / count($allIRNums) > 0.2) {
            $eg = implode('、', array_slice($nonIRNums, 0, 3));
            echo json_encode([
                'success' => false, 'message' => '退貨單號驗證失敗',
                'errors'  => ["退貨單號不符合 IR 開頭格式（共 " . count($nonIRNums) . " 個不符，範例：{$eg}），請確認資料來源"],
            ]); exit;
        }

        // 5. 日期分析
        $dates = array_filter(array_column($validRows, 'ir_date'));
        sort($dates);
        $earliestDate = $dates[0];
        $latestDate   = end($dates);

        // 6. 查詢 DB 將被清除的筆數
        $countStmt = $db->prepare("SELECT COUNT(*) FROM ir_track WHERE IR_date >= ?");
        $countStmt->execute([$earliestDate]);
        $existingDeleteCount = (int)$countStmt->fetchColumn();

        // 7. 彙整警告
        $warnings = [];
        $today       = date('Y-m-d');
        $halfYearAgo = date('Y-m-d', strtotime('-6 months'));
        if (!$headerFound)
            $warnings[] = "未找到標準欄位標題（單據日期、單據號碼），請確認欄位順序";
        if ($earliestDate > $today)
            $warnings[] = "最早日期 {$earliestDate} 為未來日期，請確認資料是否正確";
        if ($earliestDate < $halfYearAgo)
            $warnings[] = "最早日期 {$earliestDate} 超過6個月前，將大量清除歷史資料（{$existingDeleteCount} 筆）";

        // 8. 料號綁定（含客戶驗證）
        $irCustomerMap = lookupCustomerIdsByName($db, array_column($validRows, 'client_name'));
        bindDSettingIds($db, $validRows, 'd_id', 'client_name', $irCustomerMap);
        $boundCount      = count(array_filter(array_column($validRows, 'd_setting_id')));
        $autoCreateCount = count($validRows) - $boundCount;

        // 9. 前10筆預覽（DEBUG 用，前端可控制顯示/隱藏）
        $previewRows = array_slice($validRows, 0, 5);

        // 10. 暫存 Session
        $_SESSION['ir_import_rows']     = $validRows;
        $_SESSION['ir_import_earliest'] = $earliestDate;
        $_SESSION['ir_import_ts']       = time();

        echo json_encode([
            'success'               => true,
            'header_ok'             => $headerFound,
            'total_rows'            => count($validRows),
            'date_min'              => $earliestDate,
            'date_max'              => $latestDate,
            'existing_delete_count' => $existingDeleteCount,
            'ir_numbers_sample'  => array_values(array_slice($allIRNums, 0, 5)),
            'bound_count'        => $boundCount,
            'auto_create_count'  => $autoCreateCount,
            'warnings'           => $warnings,
            'preview_rows'       => $previewRows,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '解析失敗：' . $e->getMessage()]);
    }
    exit;
}

// ── 退貨單 AJAX 確認匯入 ─────────────────────────────────────────────────────
if (isset($_GET['but']) && $_GET['but'] === 'IR_List_ERP_Commit') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_SESSION['ir_import_rows']) || time() - (int)($_SESSION['ir_import_ts'] ?? 0) > 1800) {
        echo json_encode(['success' => false, 'message' => '預覽資料已過期（超過30分鐘），請重新上傳檔案']); exit;
    }

    $validRows    = $_SESSION['ir_import_rows'];
    $earliestDate = $_SESSION['ir_import_earliest'];
    $userId       = $_SESSION['id'] ?? 'excel_import';
    unset($_SESSION['ir_import_rows'], $_SESSION['ir_import_earliest'], $_SESSION['ir_import_ts']);

    try {
        $newDCount = autoCreateDSettings($db, $validRows, 'd_id', '匯入退貨單自動建立', $userId);
        $result = commitIRRows($db, $validRows, $earliestDate, $userId);
        $msg = "匯入完成！清除 {$result['deleted']} 筆舊資料，新增 {$result['inserted']} 筆";
        if ($newDCount > 0) $msg .= "，自動建立 {$newDCount} 筆新料號";
        recordUploadLog($db, 'upload_ir_erp');
        echo json_encode([
            'success'       => true,
            'deleted_rows'  => $result['deleted'],
            'inserted_rows' => $result['inserted'],
            'new_d_count'   => $newDCount,
            'message'       => $msg,
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['success' => false, 'message' => '匯入失敗：' . $e->getMessage()]);
    }
    exit;
}

// 加工單價匯入 (bom_ing_transfer_log)
if ($_GET['but'] == 'transfer_log') {

    set_time_limit(300);        // 最多執行 5 分鐘
    ini_set('memory_limit', '512M');

    if (!function_exists('quoteValue')) {
        function quoteValue($value) {
            return (is_null($value) || trim($value) === '' || strtoupper(trim($value)) === 'NULL') ? null : trim($value);
        }
    }

    if (!function_exists('convertDateFormat')) {
        function convertDateFormat($date) {
            if (empty($date)) return null;
            if (is_numeric($date)) {
                if ($date > 25569) {
                    try {
                        return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($date)->format('Y-m-d H:i:s');
                    } catch (Exception $e) {}
                }
            }
            $formatsToTry = ['Y/n/j g:i:s A', 'Y/m/d H:i:s', 'Y-m-d H:i:s', 'Y/m/d', 'Y-m-d'];
            foreach ($formatsToTry as $format) {
                $dateTime = DateTime::createFromFormat($format, trim($date));
                if ($dateTime) return $dateTime->format('Y-m-d H:i:s');
            }
            return null;
        }
    }

    if (isset($_FILES['file']['name'])) {
        $file = $_FILES['file']['tmp_name'];
        $originalName = $_FILES['file']['name'];
        $fileExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // 準備共用語句
        // 重複判斷：transfer_no 單獨唯一
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM `bom_ing_transfer_log` WHERE `transfer_no` = ?");

        // 新增語法
        $insertStmt = $db->prepare("INSERT INTO `bom_ing_transfer_log`
            (`bom`, `bom_sn`, `maker_from`, `maker_to`, `sqty`, `transfer_date`, `transfer_no`, `product_id`, `transfer_qty`, `loss_qty`, `price`, `process_amount`, `tax_amount`, `paid_qty`, `invoice_date`, `invoice_ym`, `note`, `note2`, `changed_by`, `created_at`, `modified_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        // 更新語法（transfer_no 為條件，依需求更新指定欄位）
        $updateStmt = $db->prepare("UPDATE `bom_ing_transfer_log` SET
            `sqty` = ?, `transfer_date` = ?, `transfer_qty` = ?, `loss_qty` = ?, `price` = ?,
            `process_amount` = ?, `tax_amount` = ?, `paid_qty` = ?, `invoice_date` = ?,
            `invoice_ym` = ?, `note` = ?, `note2` = ?, `changed_by` = ?, `modified_at` = ?
            WHERE `transfer_no` = ?");

        // 準備一個語句來根據 user_cname 查找 user.id
        $userStmt = $db->prepare("SELECT id FROM user WHERE user_cname = ?");

        $insertCount = 0;
        $updateCount = 0;
        $skipped_transfers = [];

        try {
            if ($fileExt === 'sql') {
                // ===== SQL 檔案處理 =====
                $sqlContent = file_get_contents($file);

                // 逐字元解析所有 INSERT INTO tmp_transfer_import 的 VALUES 區塊
                // SQL 欄位對應順序（依 INSERT INTO tmp_transfer_import 的欄位定義）：
                // bom(0), bom_sn(1), maker_from(2), maker_to(3), sqty(4), t_date(5), t_no(6),
                // p_id(7), t_qty(8), l_qty(9), price(10), p_amt(11), tax_amt(12), paid_qty(13),
                // inv_date(14), inv_ym(15), note(16), note2(17), cb(18), c_at(19), m_at(20)
                $allRowStrings = [];
                $len = strlen($sqlContent);
                $searchFrom = 0;

                // 循環找所有 INSERT INTO tmp_transfer_import 段落
                while (($insertPos = stripos($sqlContent, 'INSERT INTO tmp_transfer_import', $searchFrom)) !== false) {
                    $valuesStart = stripos($sqlContent, 'VALUES', $insertPos);
                    if ($valuesStart === false) break;
                    $i = $valuesStart + strlen('VALUES');

                    while ($i < $len) {
                        // 跳過空白
                        while ($i < $len && in_array($sqlContent[$i], [' ', "\t", "\n", "\r"])) $i++;
                        if ($i >= $len) break;
                        // 遇到非 '(' 表示此段 VALUES 結束
                        if ($sqlContent[$i] !== '(') break;
                        // 逐字元收集這一列，追蹤引號與括號深度
                        $i++; // 跳過開頭 '('
                        $rowContent = '';
                        $inQuote = false;
                        $quoteChar = '';
                        $depth = 1;
                        while ($i < $len && $depth > 0) {
                            $ch = $sqlContent[$i];
                            if ($inQuote) {
                                if ($ch === '\\') {
                                    $rowContent .= $ch;
                                    $i++;
                                    if ($i < $len) { $rowContent .= $sqlContent[$i]; $i++; }
                                    continue;
                                }
                                if ($ch === $quoteChar) $inQuote = false;
                                $rowContent .= $ch;
                            } else {
                                if ($ch === "'" || $ch === '"') { $inQuote = true; $quoteChar = $ch; $rowContent .= $ch; }
                                elseif ($ch === '(') { $depth++; $rowContent .= $ch; }
                                elseif ($ch === ')') { $depth--; if ($depth > 0) $rowContent .= $ch; }
                                else { $rowContent .= $ch; }
                            }
                            $i++;
                        }
                        $allRowStrings[] = $rowContent;
                        // 跳過列間的逗號與空白
                        while ($i < $len && in_array($sqlContent[$i], [' ', "\t", "\n", "\r", ','])) $i++;
                    }
                    $searchFrom = $i; // 從目前位置繼續找下一段
                }

                if (empty($allRowStrings)) {
                    throw new Exception("無法在 SQL 檔案中找到任何資料列");
                }

                $parsedCount = count($allRowStrings); // 記錄解析到的總列數，用於除錯
                $skippedCount = 0;

                foreach ($allRowStrings as $rowStr) {
                    // 逐字元解析逗號分隔的欄位值，正確處理字串內的逗號與括號
                    $values = [];
                    $current = '';
                    $inQuote = false;
                    $quoteChar = '';
                    $depth = 0;
                    for ($j = 0; $j < strlen($rowStr); $j++) {
                        $ch = $rowStr[$j];
                        if ($inQuote) {
                            if ($ch === '\\') {
                                $current .= $ch;
                                $j++;
                                if ($j < strlen($rowStr)) { $current .= $rowStr[$j]; }
                                continue;
                            }
                            if ($ch === $quoteChar) $inQuote = false;
                            $current .= $ch;
                        } else {
                            if ($ch === "'" || $ch === '"') { $inQuote = true; $quoteChar = $ch; $current .= $ch; }
                            elseif ($ch === '(') { $depth++; $current .= $ch; }
                            elseif ($ch === ')') { $depth--; $current .= $ch; }
                            elseif ($ch === ',' && $depth === 0) {
                                $values[] = trim($current);
                                $current = '';
                                continue;
                            } else { $current .= $ch; }
                        }
                    }
                    $values[] = trim($current);

                    // 清理每個值：移除引號，NULL 轉為 null
                    $cleanVals = [];
                    foreach ($values as $v) {
                        $v = trim($v);
                        if (strtoupper($v) === 'NULL' || $v === '') {
                            $cleanVals[] = null;
                        } else {
                            // 移除首尾單引號
                            $cleanVals[] = trim($v, "'");
                        }
                    }

                    // 補齊欄位
                    $cleanVals = array_pad($cleanVals, 21, null);

                    $transfer_no = $cleanVals[6]; // t_no
                    $bom_sn      = $cleanVals[1]; // bom_sn

                    if (empty($transfer_no)) { $skippedCount++; continue; }
                    if ($bom_sn === null) {
                        $skipped_transfers[] = $transfer_no;
                        $skippedCount++;
                        continue;
                    }

                    // cb 欄位查 user.id
                    $cb_name = $cleanVals[18];
                    $changed_by_id = 99991;
                    if (!empty($cb_name)) {
                        $userStmt->execute([$cb_name]);
                        $user_record = $userStmt->fetch(PDO::FETCH_ASSOC);
                        if ($user_record && isset($user_record['id'])) {
                            $changed_by_id = $user_record['id'];
                        }
                    }

                    $transfer_date_val = convertDateFormat($cleanVals[5]);
                    $inv_date_val      = convertDateFormat($cleanVals[14]);
                    $c_at_val          = convertDateFormat($cleanVals[19]);
                    $m_at_val          = convertDateFormat($cleanVals[20]);

                    $checkStmt->execute([$transfer_no]);
                    if ($checkStmt->fetchColumn() > 0) {
                        // 更新：依需求欄位
                        $updateStmt->execute([
                            $cleanVals[4],   // sqty
                            $transfer_date_val, // transfer_date
                            $cleanVals[8],   // transfer_qty
                            $cleanVals[9],   // loss_qty
                            $cleanVals[10],  // price
                            $cleanVals[11],  // process_amount
                            $cleanVals[12],  // tax_amount
                            $cleanVals[13],  // paid_qty
                            $inv_date_val,   // invoice_date
                            $cleanVals[15],  // invoice_ym
                            $cleanVals[16],  // note
                            $cleanVals[17],  // note2
                            $changed_by_id,  // changed_by
                            $m_at_val ?: date('Y-m-d H:i:s'), // modified_at
                            $transfer_no     // WHERE transfer_no
                        ]);
                        $updateCount++;
                    } else {
                        $insertStmt->execute([
                            $cleanVals[0],   // bom
                            $bom_sn,         // bom_sn
                            $cleanVals[2],   // maker_from
                            $cleanVals[3],   // maker_to
                            $cleanVals[4],   // sqty
                            $transfer_date_val, // transfer_date
                            $transfer_no,    // transfer_no
                            $cleanVals[7],   // product_id
                            $cleanVals[8],   // transfer_qty
                            $cleanVals[9],   // loss_qty
                            $cleanVals[10],  // price
                            $cleanVals[11],  // process_amount
                            $cleanVals[12],  // tax_amount
                            $cleanVals[13],  // paid_qty
                            $inv_date_val,   // invoice_date
                            $cleanVals[15],  // invoice_ym
                            $cleanVals[16],  // note
                            $cleanVals[17],  // note2
                            $changed_by_id,  // changed_by
                            $c_at_val ?: date('Y-m-d H:i:s'), // created_at
                            $m_at_val ?: date('Y-m-d H:i:s')  // modified_at
                        ]);
                        $insertCount++;
                    }
                }

            } else {
                // ===== Excel 檔案處理 =====
                $spreadsheet = IOFactory::load($file);
                $sheet = $spreadsheet->getActiveSheet();
                $data = $sheet->toArray();
                $data = array_slice($data, 1); // 跳過標頭列

                foreach ($data as $row) {
                    // 確保欄位足夠 (A-V 共 22 欄)
                    if (count($row) < 22) {
                        $row = array_pad($row, 22, null);
                    }

                    // 對應 Excel 欄位 (0-indexed)
                    // 0: transfer_id (忽略), 1: bom, 2: bom_sn, 3: maker_from, 4: maker_to, 5: sqty, 6: transfer_date
                    // 7: transfer_no, 8: product_id, 9: transfer_qty, 10: loss_qty, 11: price, 12: process_amount
                    // 13: tax_amount, 14: paid_qty, 15: invoice_date, 16: invoice_ym, 17: note, 18: note2
                    // 19: changed_by, 20: created_at, 21: modified_at

                    $transfer_no = quoteValue($row[7]);
                    if (empty($transfer_no)) continue;

                    $bom_sn_check = quoteValue($row[2]);
                    if ($bom_sn_check === null) {
                        $skipped_transfers[] = $transfer_no;
                        continue;
                    }

                    // 根據 Excel T欄(第20欄, index 19) 的 user_cname 查找 user.id
                    $changed_by_name = quoteValue($row[19]);
                    $changed_by_id = 99991;
                    if (!empty($changed_by_name)) {
                        $userStmt->execute([$changed_by_name]);
                        $user_record = $userStmt->fetch(PDO::FETCH_ASSOC);
                        if ($user_record && isset($user_record['id'])) {
                            $changed_by_id = $user_record['id'];
                        }
                    }

                    $transfer_date_val = convertDateFormat(quoteValue($row[6]));

                    $checkStmt->execute([$transfer_no]);
                    if ($checkStmt->fetchColumn() > 0) {
                        // 更新：依需求欄位
                        $updateStmt->execute([
                            quoteValue($row[5]),  // sqty
                            $transfer_date_val,   // transfer_date
                            quoteValue($row[9]),  // transfer_qty
                            quoteValue($row[10]), // loss_qty
                            quoteValue($row[11]), // price
                            quoteValue($row[12]), // process_amount
                            quoteValue($row[13]), // tax_amount
                            quoteValue($row[14]), // paid_qty
                            convertDateFormat(quoteValue($row[15])), // invoice_date
                            quoteValue($row[16]), // invoice_ym
                            quoteValue($row[17]), // note
                            quoteValue($row[18]), // note2
                            $changed_by_id,       // changed_by
                            convertDateFormat(quoteValue($row[21])) ?: date('Y-m-d H:i:s'), // modified_at
                            $transfer_no          // WHERE transfer_no
                        ]);
                        $updateCount++;
                    } else {
                        $insertParams = [
                            quoteValue($row[1]), // bom
                            $bom_sn_check,       // bom_sn
                            quoteValue($row[3]), // maker_from
                            quoteValue($row[4]), // maker_to
                            quoteValue($row[5]), // sqty
                            $transfer_date_val,  // transfer_date
                            $transfer_no,        // transfer_no
                            quoteValue($row[8]), // product_id
                            quoteValue($row[9]), // transfer_qty
                            quoteValue($row[10]),// loss_qty
                            quoteValue($row[11]),// price
                            quoteValue($row[12]),// process_amount
                            quoteValue($row[13]),// tax_amount
                            quoteValue($row[14]),// paid_qty
                            convertDateFormat(quoteValue($row[15])), // invoice_date
                            quoteValue($row[16]),// invoice_ym
                            quoteValue($row[17]),// note
                            quoteValue($row[18]),// note2
                            $changed_by_id,      // changed_by
                            convertDateFormat(quoteValue($row[20])) ?: date('Y-m-d H:i:s'), // created_at
                            convertDateFormat(quoteValue($row[21])) ?: date('Y-m-d H:i:s')  // modified_at
                        ];
                        $insertStmt->execute($insertParams);
                        $insertCount++;
                    }
                }
            }

            // 執行額外的資料清理與欄位更新
            $cleanupSql = "UPDATE bom_ing_transfer_log
                SET
                    /* 是否來自訂單 */
                    from_order = CASE
                        WHEN note LIKE 'O-%' THEN 1
                        ELSE NULL
                    END,

                    /* 對應訂單號 */
                    order_no = CASE
                        WHEN note LIKE 'O-%' THEN 
                            SUBSTRING_INDEX(
                                SUBSTRING_INDEX(
                                    SUBSTRING(note, 3),  -- 去掉 O-
                                ' ', 1),                -- 取第一段
                            '-', 1)                     -- 去掉 -001
                        ELSE NULL
                    END,

                    /* 更新 note */
                    note = CASE
                        /* O- 開頭 */
                        WHEN note LIKE 'O-%' THEN
                            NULLIF(
                                TRIM(
                                    SUBSTRING(note,
                                        LENGTH(SUBSTRING_INDEX(note, ' ', 1)) + 1
                                    )
                                ),
                            '')
                        /* T--000 開頭 */
                        WHEN note LIKE 'T--000%' THEN
                            NULLIF(TRIM(SUBSTRING(note, 8)), '')
                        ELSE note
                    END";
            $db->exec($cleanupSql);

            $msg = "加工單價匯入完成：新增 {$insertCount} 筆，更新 {$updateCount} 筆。";
            if ($fileExt === 'sql') {
                $msg .= "（SQL 解析列數：{$parsedCount}，跳過：{$skippedCount} 筆）";
            }
            if (!empty($skipped_transfers)) {
                $msg .= " ⚠️ 注意：單號 [" . implode(', ', $skipped_transfers) . "] 因缺少生產序號(bom_sn)未匯入。";
            }
            recordUploadLog($db, 'upload_transfer_log');
            header("Location: Upload_List.php?message=oth&msg=" . urlencode($msg));
            exit;

        } catch (Exception $e) {
            $msg = "匯入失敗：" . $e->getMessage();
            header("Location: Upload_List.php?message=oth&msg=" . urlencode($msg));
            exit;
        }
    } else {
        header("Location: Upload_List.php?message=oth&msg=" . urlencode("請上傳檔案"));
        exit;
    }
}

// 加工單價匯入-ERP 原始檔直接匯入 (bom_ing_transfer_log)
// 免先用 VBA 轉成 22 欄格式：直接吃 ERP「移轉紀錄」原始報表（64 欄，A1 起含標頭）。
// 欄位對應一律「認標頭名稱」而非固定欄位位置（ERP 版面加減欄不會錯位），民國年日期自動 +1911 轉西元。
// 對應關係（已用既有 DB 資料反查驗證）：
//   bom=移出製令, bom_sn=移轉類別(第1個), maker_from=移出單位, maker_to=移入單位,
//   sqty/transfer_qty=移轉數量, transfer_date=日期, transfer_no=單號, product_id=PRODUCTID,
//   loss_qty=損耗量, price=Price, process_amount=加工金額, tax_amount=稅金, paid_qty=付款數量,
//   invoice_date=發票日期, invoice_ym=發票年月, note=備註, note2=備註二, changed_by=異動人員(查user.id),
//   created_at=建檔日期時間, modified_at=異動日期時間
if ($_GET['but'] == 'transfer_log_raw') {

    set_time_limit(600);
    ini_set('memory_limit', '1024M');

    // 民國(或西元)日期/日期時間字串 → 'Y-m-d H:i:s'；解析失敗回 null
    // 支援：115/03/19、115/3/9、2026/03/19、115/03/19 上午 08:31:35、115/03/19 下午 01:02:03、Excel 日期序號
    if (!function_exists('rocDateTimeConvert')) {
        function rocDateTimeConvert($v) {
            if ($v === null) return null;
            $v = trim((string)$v);
            if ($v === '') return null;
            if (is_numeric($v) && $v > 25569) { // Excel 日期序號
                try {
                    return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($v)->format('Y-m-d H:i:s');
                } catch (Exception $e) {}
            }
            if (preg_match('/^(\d{2,4})\/(\d{1,2})\/(\d{1,2})(?:\s*(上午|下午)\s*(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?$/u', $v, $m)) {
                $y = (int)$m[1];
                if ($y < 1000) $y += 1911; // 民國年轉西元
                $mo = (int)$m[2]; $d = (int)$m[3];
                $h = isset($m[5]) && $m[5] !== '' ? (int)$m[5] : 0;
                $i = isset($m[6]) && $m[6] !== '' ? (int)$m[6] : 0;
                $s = isset($m[7]) && $m[7] !== '' ? (int)$m[7] : 0;
                if (isset($m[4]) && $m[4] === '下午' && $h < 12)  $h += 12;
                if (isset($m[4]) && $m[4] === '上午' && $h == 12) $h = 0;
                if (!checkdate($mo, $d, $y) || $h > 23 || $i > 59 || $s > 59) return null;
                return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $i, $s);
            }
            foreach (['Y-m-d H:i:s', 'Y-m-d', 'Y/m/d H:i:s'] as $fmt) {
                $dt = DateTime::createFromFormat($fmt, $v);
                if ($dt) return $dt->format('Y-m-d H:i:s');
            }
            return null;
        }
    }

    if (!isset($_FILES['file']['name']) || $_FILES['file']['name'] === '') {
        header("Location: Upload_List.php?message=oth&msg=" . urlencode("請上傳檔案"));
        exit;
    }

    $file    = $_FILES['file']['tmp_name'];
    $fileExt = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if (!in_array($fileExt, ['xls', 'xlsx'])) {
        header("Location: Upload_List.php?message=oth&msg=" . urlencode("原始檔匯入只接受 .xls / .xlsx 檔案"));
        exit;
    }

    try {
        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($file)->getActiveSheet();
        $data  = $sheet->toArray(null, true, false, false);
        if (count($data) < 2) throw new Exception("檔案沒有資料列");

        // 依標頭名稱找欄位位置（取第一個出現的；「移轉類別」原始檔會出現兩次，第 1 個才是製程序號）
        $colIdx = [];
        foreach ($data[0] as $i => $h) {
            $h = trim((string)$h);
            if ($h !== '' && !isset($colIdx[$h])) $colIdx[$h] = $i;
        }
        $needCols = ['日期','單號','移轉類別','移出單位','移出製令','移入單位','備註','異動人員',
                     '加工金額','稅金','Price','移轉數量','損耗量','PRODUCTID','發票日期','發票年月',
                     '建檔日期時間','異動日期時間','付款數量','備註二'];
        $missing = array_diff($needCols, array_keys($colIdx));
        if (!empty($missing)) {
            throw new Exception("這不是 ERP 移轉紀錄原始檔：第 1 列找不到必要欄位標題【" . implode('、', $missing) . "】，請確認上傳的檔案是否正確");
        }

        // ── 第一階段：全檔驗證與整理（不動 DB，有錯直接擋下） ──
        $rows = [];              // 通過驗證待寫入的列
        $rowErrors = [];         // 格式錯誤（會擋下整個匯入）
        $skippedNoTransferNo = 0;
        $skippedNoBomSn = [];    // 缺製程序號被跳過的單號（與舊功能相同：跳過並警告）
        $skippedZeroPrice = 0;   // 單價 0/空白/負數：整列不寫入（生管/成本頁取「最新一筆」單價，0 價列會蓋掉正確單價）
        $numFields = ['加工金額','稅金','Price','移轉數量','損耗量','付款數量'];

        for ($r = 1; $r < count($data); $r++) {
            $row = $data[$r];
            $g = function ($name) use ($row, $colIdx) {
                $v = $row[$colIdx[$name]] ?? null;
                if ($v === null) return null;
                $v = trim((string)$v);
                return ($v === '' || strtoupper($v) === 'NULL') ? null : $v;
            };
            $rowNo = $r + 1; // Excel 實際列號

            $transfer_no = $g('單號');
            if ($transfer_no === null) { $skippedNoTransferNo++; continue; }

            $bom_sn = $g('移轉類別');
            if ($bom_sn === null) { $skippedNoBomSn[] = $transfer_no; continue; }
            if (!is_numeric($bom_sn)) { $rowErrors[] = "第{$rowNo}列 {$transfer_no}：移轉類別(製程序號)不是數字 [{$bom_sn}]"; continue; }

            $bom = $g('移出製令');
            if ($bom === null) { $rowErrors[] = "第{$rowNo}列 {$transfer_no}：移出製令(BOM)空白"; continue; }

            foreach ($numFields as $nf) {
                $v = $g($nf);
                if ($v !== null && !is_numeric($v)) {
                    $rowErrors[] = "第{$rowNo}列 {$transfer_no}：{$nf} 不是數字 [{$v}]";
                    continue 2;
                }
            }

            $transfer_date = rocDateTimeConvert($g('日期'));
            if ($g('日期') !== null && $transfer_date === null) {
                $rowErrors[] = "第{$rowNo}列 {$transfer_no}：日期格式無法解析 [" . $g('日期') . "]";
                continue;
            }
            $invoice_date = rocDateTimeConvert($g('發票日期'));
            if ($g('發票日期') !== null && $invoice_date === null) {
                $rowErrors[] = "第{$rowNo}列 {$transfer_no}：發票日期格式無法解析 [" . $g('發票日期') . "]";
                continue;
            }

            $invoice_ym = $g('發票年月');
            if ($invoice_ym !== null && !preg_match('/^\d{6}$/', $invoice_ym)) {
                $rowErrors[] = "第{$rowNo}列 {$transfer_no}：發票年月不是 6 碼年月 [{$invoice_ym}]";
                continue;
            }

            // 單價 0/空白/負數的列整筆跳過（不新增也不更新）——尚未計價的移轉不進系統
            $priceVal = $g('Price');
            if ($priceVal === null || (float)$priceVal <= 0) { $skippedZeroPrice++; continue; }

            $rows[] = [
                'bom'            => $bom,
                'bom_sn'         => $bom_sn,
                'maker_from'     => $g('移出單位'),
                'maker_to'       => $g('移入單位'),
                'sqty'           => $g('移轉數量'),
                'transfer_date'  => $transfer_date,
                'transfer_no'    => $transfer_no,
                'product_id'     => $g('PRODUCTID'),
                'transfer_qty'   => $g('移轉數量'),
                'loss_qty'       => $g('損耗量'),
                'price'          => $g('Price'),
                'process_amount' => $g('加工金額'),
                'tax_amount'     => $g('稅金'),
                'paid_qty'       => $g('付款數量'),
                'invoice_date'   => $invoice_date,
                'invoice_ym'     => $invoice_ym,
                'note'           => $g('備註'),
                'note2'          => $g('備註二'),
                'changer_name'   => $g('異動人員'),
                'created_at'     => rocDateTimeConvert($g('建檔日期時間')) ?: date('Y-m-d H:i:s'),
                'modified_at'    => rocDateTimeConvert($g('異動日期時間')) ?: date('Y-m-d H:i:s'),
            ];
        }

        if (!empty($rowErrors)) {
            $show = array_slice($rowErrors, 0, 10);
            $more = count($rowErrors) > 10 ? "…等共 " . count($rowErrors) . " 個錯誤" : "";
            throw new Exception("資料驗證失敗，未匯入任何資料：\n" . implode("\n", $show) . $more);
        }
        if (empty($rows)) throw new Exception("沒有可匯入的資料列" . ($skippedZeroPrice > 0 ? "（單價為 0 跳過 {$skippedZeroPrice} 列）" : ""));

        // ── 第二階段：寫入 DB（單一 transaction，全部成功才生效） ──
        $db->beginTransaction();

        // 預先查出已存在的單號（避免逐列 SELECT）
        $existingNos = [];
        $allNos = array_column($rows, 'transfer_no');
        foreach (array_chunk(array_unique($allNos), 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $db->prepare("SELECT transfer_no FROM bom_ing_transfer_log WHERE transfer_no IN ($ph)");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) $existingNos[$n] = true;
        }

        $insertStmt = $db->prepare("INSERT INTO `bom_ing_transfer_log`
            (`bom`, `bom_sn`, `maker_from`, `maker_to`, `sqty`, `transfer_date`, `transfer_no`, `product_id`, `transfer_qty`, `loss_qty`, `price`, `process_amount`, `tax_amount`, `paid_qty`, `invoice_date`, `invoice_ym`, `note`, `note2`, `changed_by`, `created_at`, `modified_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $updateStmt = $db->prepare("UPDATE `bom_ing_transfer_log` SET
            `sqty` = ?, `transfer_date` = ?, `transfer_qty` = ?, `loss_qty` = ?, `price` = ?,
            `process_amount` = ?, `tax_amount` = ?, `paid_qty` = ?, `invoice_date` = ?,
            `invoice_ym` = ?, `note` = ?, `note2` = ?, `changed_by` = ?, `modified_at` = ?
            WHERE `transfer_no` = ?");
        $userStmt = $db->prepare("SELECT id FROM user WHERE user_cname = ?");

        $userCache = [];        // 異動人員姓名 → user.id（查不到 = 99991，同舊功能）
        $unknownChangers = [];
        $insertCount = 0;
        $updateCount = 0;

        foreach ($rows as $row) {
            $cname = $row['changer_name'];
            if ($cname === null) {
                $changed_by_id = 99991;
            } elseif (isset($userCache[$cname])) {
                $changed_by_id = $userCache[$cname];
            } else {
                $userStmt->execute([$cname]);
                $u = $userStmt->fetch(PDO::FETCH_ASSOC);
                $changed_by_id = ($u && isset($u['id'])) ? $u['id'] : 99991;
                if ($changed_by_id == 99991) $unknownChangers[$cname] = true;
                $userCache[$cname] = $changed_by_id;
            }

            if (isset($existingNos[$row['transfer_no']])) {
                $updateStmt->execute([
                    $row['sqty'], $row['transfer_date'], $row['transfer_qty'], $row['loss_qty'], $row['price'],
                    $row['process_amount'], $row['tax_amount'], $row['paid_qty'], $row['invoice_date'],
                    $row['invoice_ym'], $row['note'], $row['note2'], $changed_by_id, $row['modified_at'],
                    $row['transfer_no'],
                ]);
                $updateCount++;
            } else {
                $insertStmt->execute([
                    $row['bom'], $row['bom_sn'], $row['maker_from'], $row['maker_to'], $row['sqty'],
                    $row['transfer_date'], $row['transfer_no'], $row['product_id'], $row['transfer_qty'],
                    $row['loss_qty'], $row['price'], $row['process_amount'], $row['tax_amount'], $row['paid_qty'],
                    $row['invoice_date'], $row['invoice_ym'], $row['note'], $row['note2'], $changed_by_id,
                    $row['created_at'], $row['modified_at'],
                ]);
                $insertCount++;
                $existingNos[$row['transfer_no']] = true; // 同檔重複單號時第二筆改走更新
            }
        }

        // 備註清理（與舊功能同邏輯，但只針對本次匯入的單號，不動其他資料）：
        // O- 開頭：拆出訂單號寫入 from_order/order_no，note 留下訂單號之後的文字
        // T--000 開頭：去掉 T--000 前綴留下說明文字
        foreach (array_chunk(array_unique($allNos), 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $stO = $db->prepare("UPDATE bom_ing_transfer_log SET
                    from_order = 1,
                    order_no = SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING(note, 3), ' ', 1), '-', 1),
                    note = NULLIF(TRIM(SUBSTRING(note, LENGTH(SUBSTRING_INDEX(note, ' ', 1)) + 1)), '')
                WHERE note LIKE 'O-%' AND transfer_no IN ($ph)");
            $stO->execute($chunk);
            $stT = $db->prepare("UPDATE bom_ing_transfer_log SET
                    note = NULLIF(TRIM(SUBSTRING(note, 8)), '')
                WHERE note LIKE 'T--000%' AND transfer_no IN ($ph)");
            $stT->execute($chunk);
        }

        recordUploadLog($db, 'upload_transfer_log_raw');
        $db->commit();

        $msg = "加工單價原始檔匯入完成：新增 {$insertCount} 筆，更新 {$updateCount} 筆。";
        if ($skippedZeroPrice > 0) $msg .= "\n單價為 0（尚未計價）跳過 {$skippedZeroPrice} 列未寫入。";
        if ($skippedNoTransferNo > 0) $msg .= "\n單號空白跳過 {$skippedNoTransferNo} 列。";
        if (!empty($skippedNoBomSn)) {
            $msg .= "\n⚠️ 注意：單號 [" . implode(', ', $skippedNoBomSn) . "] 因缺少製程序號(移轉類別)未匯入。";
        }
        if (!empty($unknownChangers)) {
            $msg .= "\n⚠️ 異動人員 [" . implode(', ', array_keys($unknownChangers)) . "] 在系統查無此人，已用預設人員(99991)記錄。";
        }
        $_SESSION['upload_message'] = $msg;
        header("Location: Upload_List.php?message=oth");
        exit;

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $_SESSION['upload_message'] = "匯入失敗（資料未寫入）：" . $e->getMessage();
        header("Location: Upload_List.php?message=oth");
        exit;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// BOM／BOM_ING ERP直接匯入 (N.xlsx，2026-07-20新增)
// 取代「建立更新_BOM」VBA巨集的建檔部分：直接讀 ERP 匯出的 N.xlsx，免先跑巨集轉檔。
// 欄位對照規則以真實資料核對定案（見 page_change_log）：
//   bom.bom=製令單號、bom.d_id=半成品編號、Client_Name 查 d_setting.Customer_Id→customer_list.customer（不查Excel客戶表）
//   bom_ing.process_no=製程代號 直接對應 process_no.ProcessNo（不查Excel交期試算）、bom_sn=生產序號(ERP已排序，免自算)
//   bom_ing_id = RIGHT(bom,9)-process_no-bom_sn-sqty-maker_id_no（比照原巨集公式）
// 兩階段流程比照 IS_List_ERP_Preview/Commit。
// ══════════════════════════════════════════════════════════════════════════════

// 備註欄清理：ERP「備註」開頭常帶「O-訂單號」前綴（如 O-OO1150716015-001 SCM415...）或「T--000」前綴，
// 真實資料只留前綴後面的文字（已用既有真實 bom_ing.ps 資料核對，規則同 transfer_log_raw/VBA 的備註清理）
if (!function_exists('bomErpCleanPs')) {
    function bomErpCleanPs($ps) {
        if ($ps === null) return null;
        if (strpos($ps, 'O-') === 0) {
            $sp = strpos($ps, ' ');
            $ps = $sp === false ? '' : trim(substr($ps, $sp + 1));
        } elseif (strpos($ps, 'T--000') === 0) {
            $ps = trim(substr($ps, 6));
        }
        return $ps === '' ? null : $ps;
    }
}

// 解析 N.xlsx → 依製令單號(bom)分組，每組＝一個 bom + 多筆 bom_ing
// 注意：N.xlsx 的「預移入日/預移出日」是ERP排程的「預計」日期，跟 bom_ing.outsource_date/return_date
// （實際委外日/實際回廠日）語意不同（已用既有真實資料核對：VBA建檔當下這兩欄一律留null，由另一支每日
// 移送流程回填實際日期），所以這裡不解析、也不寫入這兩欄，避免把「預計」誤植為「實際」。
if (!function_exists('parseBomErpRows')) {
    function parseBomErpRows($allRows) {
        if (count($allRows) < 2) throw new Exception('檔案沒有資料列');

        // 依標頭名稱找欄位位置（比照 transfer_log_raw，避免欄位順序跟舊 VBA 假設的固定位置不符）
        $colIdx = [];
        foreach ($allRows[0] as $i => $h) {
            $h = trim((string)$h);
            if ($h !== '' && !isset($colIdx[$h])) $colIdx[$h] = $i;
        }
        $needCols = ['製令單號', '製程代號', '生產單位', '生產數量', '預移入日', '預移出日', '備註', '生產序號', '半成品編號'];
        $missing = array_diff($needCols, array_keys($colIdx));
        if (!empty($missing)) {
            throw new Exception('這不是 ERP BOM 原始檔：第1列找不到必要欄位標題【' . implode('、', $missing) . '】，請確認上傳的檔案是否正確');
        }

        $groups = []; // bom => ['d_id'=>, 'sqty'=>, 'rows'=>[...]]
        for ($r = 1; $r < count($allRows); $r++) {
            $row = $allRows[$r];
            $g = function ($name) use ($row, $colIdx) {
                $v = $row[$colIdx[$name]] ?? null;
                if ($v === null) return null;
                $v = trim((string)$v);
                return ($v === '' || strtoupper($v) === 'NULL') ? null : $v;
            };

            $bom  = $g('製令單號');
            $d_id = $g('半成品編號');
            if ($bom === null || $d_id === null) continue; // 缺關鍵欄位（如小計/空白列）跳過

            $process_no = $g('製程代號');
            $bom_sn     = $g('生產序號');
            if (!is_numeric($process_no) || !is_numeric($bom_sn)) continue; // 非資料列跳過

            $sqty = $g('生產數量');

            if (!isset($groups[$bom])) {
                $groups[$bom] = ['d_id' => $d_id, 'sqty' => $sqty, 'rows' => []];
            }
            $groups[$bom]['rows'][] = [
                'process_no'     => (int)$process_no,
                'maker_id_no'    => $g('生產單位'),
                'sqty'           => is_numeric($sqty) ? (int)$sqty : null,
                'bom_sn'         => (int)$bom_sn,
                'ps'             => $g('備註'),
            ];
        }
        return $groups;
    }
}

// ── 入口①：AJAX 預覽 — 解析 + 分組 + 對照查找 + 暫存 Session（30分鐘）────
if (isset($_GET['but']) && $_GET['but'] === 'BOM_ERP_Preview') {
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(120);
    ini_set('memory_limit', '512M');

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => '請選擇要上傳的檔案']); exit;
    }

    try {
        $spreadsheet = IOFactory::load($_FILES['file']['tmp_name']);
        $allRows     = $spreadsheet->getActiveSheet()->toArray();
        $groups      = parseBomErpRows($allRows);

        if (empty($groups)) {
            echo json_encode(['success' => false, 'message' => '未找到有效資料列，請確認是否為 ERP BOM 原始檔']); exit;
        }

        // 既有 BOM 檢查
        $bomList = array_keys($groups);
        $existingBoms = [];
        foreach (array_chunk($bomList, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $db->prepare("SELECT bom FROM bom WHERE bom IN ($ph)");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $b) $existingBoms[$b] = true;
        }

        // 客戶查詢：d_id(半成品編號=料號) → d_setting.Customer_Id → customer_list.customer
        $dIds = array_values(array_unique(array_column($groups, 'd_id')));
        $customerByDId = [];
        foreach (array_chunk($dIds, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $db->prepare("SELECT ds.D_Setting_Id, cl.customer
                                 FROM d_setting ds LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
                                 WHERE ds.D_Setting_Id IN ($ph)");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $customerByDId[$row['D_Setting_Id']] = $row['customer'];
        }

        // 製程主檔（顯示用）
        $processNames = [];
        foreach ($db->query("SELECT ProcessNo, ProcessName FROM process_no")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $processNames[(int)$row['ProcessNo']] = $row['ProcessName'];
        }

        // 廠商主檔
        $makerIdNoSet = [];
        foreach ($groups as $grp) foreach ($grp['rows'] as $row) if ($row['maker_id_no'] !== null) $makerIdNoSet[$row['maker_id_no']] = true;
        $makerNames = [];
        foreach (array_chunk(array_keys($makerIdNoSet), 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $db->prepare("SELECT maker_id_no, maker_id FROM maker_list WHERE maker_id_no IN ($ph)");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $makerNames[$row['maker_id_no']] = $row['maker_id'];
        }

        // 統計與警告
        $newBomCount = 0; $existingBomCount = 0; $totalIngRows = 0;
        $unknownProcess = []; $unknownMaker = []; $unknownCustomer = [];
        foreach ($groups as $bom => $grp) {
            if (isset($existingBoms[$bom])) $existingBomCount++; else $newBomCount++;
            if (empty($customerByDId[$grp['d_id']])) $unknownCustomer[$grp['d_id']] = true;
            foreach ($grp['rows'] as $row) {
                $totalIngRows++;
                if (!isset($processNames[$row['process_no']])) $unknownProcess[$row['process_no']] = true;
                if ($row['maker_id_no'] !== null && !isset($makerNames[$row['maker_id_no']])) $unknownMaker[$row['maker_id_no']] = true;
            }
        }

        $warnings = [];
        if (!empty($unknownProcess)) $warnings[] = '製程代號查無 process_no 主檔對應（' . implode('、', array_keys($unknownProcess)) . '），該筆製程名稱將顯示空白，不影響匯入';
        if (!empty($unknownMaker)) $warnings[] = '生產單位查無 maker_list 廠商主檔（' . implode('、', array_keys($unknownMaker)) . '），maker_id 簡稱將留空';
        if (!empty($unknownCustomer)) {
            $sample = array_slice(array_keys($unknownCustomer), 0, 10);
            $warnings[] = '料號查無客戶綁定（' . implode('、', $sample) . (count($unknownCustomer) > 10 ? '…等共' . count($unknownCustomer) . '筆' : '') . '），Client_Name 將留空';
        }

        // 預覽樣本（前5個BOM）
        $previewRows = [];
        foreach (array_slice($groups, 0, 5, true) as $bom => $grp) {
            $previewRows[] = [
                'bom'           => $bom,
                'd_id'          => $grp['d_id'],
                'sqty'          => $grp['sqty'],
                'client_name'   => $customerByDId[$grp['d_id']] ?? null,
                'is_new'        => !isset($existingBoms[$bom]),
                'process_count' => count($grp['rows']),
                'processes'     => implode('、', array_map(fn($row) => $processNames[$row['process_no']] ?? ('#' . $row['process_no']), $grp['rows'])),
            ];
        }

        $_SESSION['bom_erp_import_groups'] = $groups;
        $_SESSION['bom_erp_import_ts']     = time();

        echo json_encode([
            'success'             => true,
            'bom_count'           => count($groups),
            'new_bom_count'       => $newBomCount,
            'existing_bom_count'  => $existingBomCount,
            'total_ing_rows'      => $totalIngRows,
            'warnings'            => $warnings,
            'preview_rows'        => $previewRows,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '解析失敗：' . $e->getMessage()]);
    }
    exit;
}

// ── 入口②：AJAX 確認匯入 — 讀 Session → transaction 寫入 bom + bom_ing ────
if (isset($_GET['but']) && $_GET['but'] === 'BOM_ERP_Commit') {
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(300);
    ini_set('memory_limit', '512M');

    if (!isset($_SESSION['bom_erp_import_groups']) || time() - (int)($_SESSION['bom_erp_import_ts'] ?? 0) > 1800) {
        echo json_encode(['success' => false, 'message' => '預覽資料已過期（超過30分鐘），請重新上傳檔案']); exit;
    }

    $groups = $_SESSION['bom_erp_import_groups'];
    $userId = $_SESSION['id'] ?? 'excel_import';
    unset($_SESSION['bom_erp_import_groups'], $_SESSION['bom_erp_import_ts']);

    try {
        $db->beginTransaction();

        // 客戶查詢（同 Preview，重新查一次確保資料最新）
        $dIds = array_values(array_unique(array_column($groups, 'd_id')));
        $customerByDId = [];
        foreach (array_chunk($dIds, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $db->prepare("SELECT ds.D_Setting_Id, cl.customer
                                 FROM d_setting ds LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
                                 WHERE ds.D_Setting_Id IN ($ph)");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $customerByDId[$row['D_Setting_Id']] = $row['customer'];
        }

        // 廠商主檔
        $makerIdNoSet = [];
        foreach ($groups as $grp) foreach ($grp['rows'] as $row) if ($row['maker_id_no'] !== null) $makerIdNoSet[$row['maker_id_no']] = true;
        $makerNames = [];
        foreach (array_chunk(array_keys($makerIdNoSet), 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $db->prepare("SELECT maker_id_no, maker_id FROM maker_list WHERE maker_id_no IN ($ph)");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $makerNames[$row['maker_id_no']] = $row['maker_id'];
        }

        // 製程主檔（QC 檢驗表分頁命名用）
        $processNames = [];
        foreach ($db->query("SELECT ProcessNo, ProcessName FROM process_no")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $processNames[(int)$row['ProcessNo']] = $row['ProcessName'];
        }

        // bom：只更新這次真正握有資料的欄位，不動 o_order_id/processing_state/Delivery_date 等其他流程維護的欄位
        $bomExistsStmt = $db->prepare("SELECT bom FROM bom WHERE bom = ?");
        $bomInsertStmt = $db->prepare("INSERT INTO bom (bom, d_id, sqty, Client_Name, state, Created_By, Created_At, Modified_By, Modified_At)
                                        VALUES (?, ?, ?, ?, 'ing', ?, NOW(), ?, NOW())");
        $bomUpdateStmt = $db->prepare("UPDATE bom SET d_id = ?, sqty = ?, Client_Name = ?, Modified_By = ?, Modified_At = NOW() WHERE bom = ?");

        // bom_ing：以 (bom, bom_sn) 判斷是否已存在（比照 u5_NEW 既有邏輯，資料庫無 bom_ing_id 唯一鍵）
        $ingExistsStmt = $db->prepare("SELECT bom_ing_fid FROM bom_ing WHERE bom = ? AND bom_sn = ?");
        $ingInsertStmt = $db->prepare("INSERT INTO bom_ing
                                        (bom_ing_id, bom, process_no, maker_id_no, maker_id, sqty, bom_sn,
                                         processing_state, ps, Created_By, Created_At, Modified_By, Modified_At)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, 'N', ?, ?, NOW(), ?, NOW())");
        // Phase A：BOM 匯入不寫廠商（讓「maker 空＝未移轉」成立，實際廠商由當天移轉才填）。
        // UPDATE 既有列不碰 maker（保護已被移轉填過的列），也不動 processing_state。
        $ingUpdateStmt = $db->prepare("UPDATE bom_ing SET
                                        sqty = ?, ps = ?,
                                        Modified_By = ?, Modified_At = NOW()
                                        WHERE bom_ing_fid = ?");

        $newBomCount = 0; $updatedBomCount = 0; $newIngCount = 0; $updatedIngCount = 0;
        $newBoms = []; // 新建的 BOM，供 commit 後產生 QC 檢驗表

        foreach ($groups as $bom => $grp) {
            $clientName = $customerByDId[$grp['d_id']] ?? null;
            $sqty       = is_numeric($grp['sqty']) ? (int)$grp['sqty'] : null;

            $bomExistsStmt->execute([$bom]);
            if ($bomExistsStmt->fetch()) {
                $bomUpdateStmt->execute([$grp['d_id'], $sqty, $clientName, $userId, $bom]);
                $updatedBomCount++;
            } else {
                $bomInsertStmt->execute([$bom, $grp['d_id'], $sqty, $clientName, $userId, $userId]);
                $newBomCount++;
                $newBoms[$bom] = ['d_id' => $grp['d_id'], 'sqty' => $sqty, 'client' => $clientName];
            }

            $right9 = substr($bom, -9); // 比照原巨集 RIGHT(bom,9)

            foreach ($grp['rows'] as $row) {
                $makerId  = $row['maker_id_no'] !== null ? ($makerNames[$row['maker_id_no']] ?? null) : null;
                // bom_ing_id 格式不變（仍用 N.xlsx 生產單位組字串，此鍵廣用於34+檔案不可改格式）
                $bomIngId = $right9 . '-' . $row['process_no'] . '-' . $row['bom_sn'] . '-' . $row['sqty'] . '-' . $row['maker_id_no'];
                $ps       = bomErpCleanPs($row['ps']);

                // Phase A：只有客供料(138)例外把廠商寫進 maker 欄位（其廠商=客戶、開BOM即知、不走委外移轉）；
                // 其餘製程 maker 欄位一律留 NULL，等當天移轉才填 → 「maker 空＝未移轉」成立。
                $isCustSupplied = ((int)$row['process_no'] === 138);
                $storeMakerNo   = $isCustSupplied ? $row['maker_id_no'] : null;
                $storeMakerId   = $isCustSupplied ? $makerId : null;

                $ingExistsStmt->execute([$bom, $row['bom_sn']]);
                $existingFid = $ingExistsStmt->fetchColumn();
                if ($existingFid) {
                    $ingUpdateStmt->execute([
                        $row['sqty'], $ps, $userId, $existingFid,
                    ]);
                    $updatedIngCount++;
                } else {
                    $ingInsertStmt->execute([
                        $bomIngId, $bom, $row['process_no'], $storeMakerNo, $storeMakerId, $row['sqty'], $row['bom_sn'],
                        $ps, $userId, $userId,
                    ]);
                    $newIngCount++;
                }
            }
        }

        recordUploadLog($db, 'upload_bom_erp');
        $db->commit();

        // ── commit 後：對新建的 BOM 產生 QC 檢驗紀錄表 .xlsm 到 NAS（同舊 VBA）──
        // 非阻斷：DB 已提交，此步失敗只回報警告不影響匯入結果；檔案已存在則跳過（同 VBA）。
        // NAS 路徑一律即時從 system_settings 組出（遵鐵律5，不寫死）。
        $qcGenerated = 0; $qcSkipped = 0; $qcErrors = [];
        if (!empty($newBoms)) {
            try {
                $stCfg = $db->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('qc_form_nas_dir','qc_form_template_name')");
                $stCfg->execute();
                $cfg = [];
                foreach ($stCfg->fetchAll(PDO::FETCH_ASSOC) as $c) $cfg[$c['setting_key']] = $c['setting_value'];
                $nasDir   = rtrim($cfg['qc_form_nas_dir'] ?? '', "\\/");
                $tmplName = $cfg['qc_form_template_name'] ?? '2-QA-01-06-檢驗記錄表 V04.xlsm';

                if ($nasDir === '') {
                    $qcErrors[] = '未設定 qc_form_nas_dir，略過 QC 檢驗表產生';
                } else {
                    $tmplPath = $nasDir . DIRECTORY_SEPARATOR . $tmplName;
                    foreach ($newBoms as $bom => $meta) {
                        $outPath = $nasDir . DIRECTORY_SEPARATOR . $bom . '.xlsm';
                        if (is_file($outPath)) { $qcSkipped++; continue; } // 已存在跳過（同 VBA）
                        // 組製程清單（排除材料/包裝/客供料，同 VBA），依 bom_sn 排序
                        $procs = [];
                        $rows = $groups[$bom]['rows'];
                        usort($rows, fn($a, $b) => $a['bom_sn'] <=> $b['bom_sn']);
                        foreach ($rows as $row) {
                            $pname = $processNames[$row['process_no']] ?? '';
                            if (qcFormExcludedProcess($pname)) continue;
                            $procs[] = ['name' => $pname, 'ps1' => bomErpCleanPs($row['ps']) ?? '', 'ps2' => ''];
                        }
                        try {
                            qcFormGenerate($tmplPath, $outPath, [
                                'bom' => $bom, 'd_id' => $meta['d_id'], 'sqty' => $meta['sqty'],
                                'client' => $meta['client'] ?? '', 'delivery' => '', 'pack_ps2' => '',
                                'processes' => $procs,
                            ]);
                            $qcGenerated++;
                        } catch (Exception $ge) {
                            $qcErrors[] = "{$bom}：" . $ge->getMessage();
                        }
                    }
                }
            } catch (Exception $ce) {
                $qcErrors[] = 'QC 檢驗表產生流程異常：' . $ce->getMessage();
            }
        }

        $msg = "匯入完成！新增 BOM {$newBomCount} 筆、更新 {$updatedBomCount} 筆；新增製程 {$newIngCount} 筆、更新 {$updatedIngCount} 筆";
        if ($qcGenerated > 0 || $qcSkipped > 0) $msg .= "；QC檢驗表 產生 {$qcGenerated} 份" . ($qcSkipped > 0 ? "、已存在跳過 {$qcSkipped} 份" : '');
        if (!empty($qcErrors)) $msg .= "。⚠ QC檢驗表 部分未產生：" . implode('；', array_slice($qcErrors, 0, 5));

        echo json_encode([
            'success'            => true,
            'new_bom_count'      => $newBomCount,
            'updated_bom_count'  => $updatedBomCount,
            'new_ing_count'      => $newIngCount,
            'updated_ing_count'  => $updatedIngCount,
            'qc_generated'       => $qcGenerated,
            'qc_skipped'         => $qcSkipped,
            'qc_errors'          => $qcErrors,
            'message'            => $msg,
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['success' => false, 'message' => '匯入失敗：' . $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// 移轉紀錄 ERP直接匯入 (SupQuery.xls.xlsx，2026-07-20新增)
// 取代「更新_當天移送」VBA巨集＋人工上傳 s-OK 檔（but=u5）的流程：直接讀 ERP 匯出的移轉原始檔。
// 對照規則由 BOM.xlsm VBA 原始碼（olevba抽出「更新_移送」）＋欄位剪貼模擬＋真實資料三重驗證定案：
//   有效列＝製程代號非空 且 生產單位非空 且 預移入日==預移出日（VBA註解：判定製程為空者、移入移出日期不同者刪除）
//   同(bom,bom_sn)多筆時，只取異動日期時間最新的一筆（VBA依異動時間排序後取最後一筆）
//   更新語意鏡像既有 u5：以(bom,bom_sn)找到既有 bom_ing 列→ processing_state='ing'、
//   outsource_date=Created_At=預移入日、Created_By=異動人員→user.id、maker/sqty/process_no/ps 帶入本列值、
//   其餘 QC_check/QC_check_date/return_date/Delivery_date/PS2/1_side/machine_id/processing_sequence/
//   Modified_By/Modified_At 一律清為 NULL（與 u5 既有行為一致，已用 DB 既有 ing 列驗證 Modified_At=NULL 等特徵）；
//   查無(bom,bom_sn)則 INSERT（bom_ing_id 公式同 BOM 建檔）。
// ══════════════════════════════════════════════════════════════════════════════

// 民國日期(115/07/17)或含上午/下午時間(115/04/22 下午 05:32:35)→'Y-m-d H:i:s'；解析失敗回 null
if (!function_exists('transferErpRocDate')) {
    function transferErpRocDate($v) {
        if ($v === null) return null;
        $v = trim((string)$v);
        if ($v === '') return null;
        if (is_numeric($v) && $v > 25569) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($v)->format('Y-m-d H:i:s');
            } catch (Exception $e) {}
        }
        if (preg_match('/^(\d{2,4})\/(\d{1,2})\/(\d{1,2})(?:\s*(上午|下午)\s*(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?$/u', $v, $m)) {
            $y = (int)$m[1];
            if ($y < 1000) $y += 1911;
            $mo = (int)$m[2]; $d = (int)$m[3];
            $h = isset($m[5]) && $m[5] !== '' ? (int)$m[5] : 0;
            $i = isset($m[6]) && $m[6] !== '' ? (int)$m[6] : 0;
            $sec = isset($m[7]) && $m[7] !== '' ? (int)$m[7] : 0;
            if (isset($m[4]) && $m[4] === '下午' && $h < 12)  $h += 12;
            if (isset($m[4]) && $m[4] === '上午' && $h == 12) $h = 0;
            if (!checkdate($mo, $d, $y) || $h > 23 || $i > 59 || $sec > 59) return null;
            return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $i, $sec);
        }
        return null;
    }
}

// 移轉日期是否為合理的真實業務日期（排除無效資料）：
//  - 1911/1/1：ERP 空值佔位（民國0年），代表「尚未移入」，非真實移轉 → 無效
//  - 民國年異常（如 180/05/17 = 西元2091）→ 無效
// 合理範圍：西元 2000 ~ 明年（民國89以後、且不超過現在明年）
if (!function_exists('transferErpValidBizDate')) {
    function transferErpValidBizDate($ymd) {
        if ($ymd === null) return false;
        $y = (int)substr($ymd, 0, 4);
        return $y >= 2000 && $y <= ((int)date('Y') + 1);
    }
}

// Phase B 覆蓋判定：對既有 bom_ing 列決定本次移轉該怎麼處理。
// 回傳 'insert'（列不存在）| 'write'（寫入/覆蓋）| 'skip_dup'（同一加工單號、重複匯入）| 'skip_stale'（舊檔，防回捲）。
// $existing = 既有列 assoc（含 transfer_no/transfer_changed_at/QC_check/return_date/qc_completed）或 null。
// 規則：
//  - 同加工單號 → 冪等跳過（bom_ing 不動，價格可另補）。
//  - 已有進度（QC_check/return_date/qc_completed）→ 僅在「本次異動時間 ≥ 已記錄」才覆蓋，否則跳過保護（含 legacy 無時間列一律保護）。
//  - 無進度 → 除非本次比已記錄舊，否則寫入（涵蓋 Phase A 全新 maker 空列、legacy 未移轉列）。
if (!function_exists('transferErpClassify')) {
    function transferErpClassify($existing, $inTransferNo, $inChangedTs) {
        if (empty($existing)) return 'insert';
        $exNo = $existing['transfer_no'] ?? null;
        if ($inTransferNo !== null && $exNo !== null && $exNo === $inTransferNo) return 'skip_dup';
        $exChanged   = $existing['transfer_changed_at'] ?? null;
        $hasProgress = !empty($existing['QC_check']) || !empty($existing['return_date']) || (int)($existing['qc_completed'] ?? 0) === 1;
        if ($hasProgress) {
            return ($exChanged !== null && $inChangedTs >= $exChanged) ? 'write' : 'skip_stale';
        }
        if ($exChanged !== null && $inChangedTs < $exChanged) return 'skip_stale';
        return 'write';
    }
}

// 解析移轉原始檔 → 過濾有效列＋同(bom,bom_sn)去重(留異動時間最新) → 回傳列陣列
if (!function_exists('parseTransferErpRows')) {
    function parseTransferErpRows($allRows, &$stats) {
        if (count($allRows) < 2) throw new Exception('檔案沒有資料列');

        $colIdx = [];
        foreach ($allRows[0] as $i => $h) {
            $h = trim((string)$h);
            if ($h !== '' && !isset($colIdx[$h])) $colIdx[$h] = $i;
        }
        $needCols = ['加工單號', '製令單號', '製程代號', '生產單位', '生產數量', '預移入日', '預移出日', '備註', '生產序號', '異動人員', '異動日期時間'];
        $missing = array_diff($needCols, array_keys($colIdx));
        if (!empty($missing)) {
            throw new Exception('這不是 ERP 移轉原始檔：第1列找不到必要欄位標題【' . implode('、', $missing) . '】，請確認上傳的檔案是否正確');
        }

        $stats = ['skip_no_process' => 0, 'skip_no_maker' => 0, 'skip_invalid_date' => 0, 'skip_date_diff' => 0, 'dedup_dropped' => 0];
        $byKey = []; // "bom|bom_sn" => row（留異動時間最新）
        for ($r = 1; $r < count($allRows); $r++) {
            $row = $allRows[$r];
            $g = function ($name) use ($row, $colIdx) {
                $idx = $colIdx[$name] ?? null;
                if ($idx === null) return null;      // 選填欄位(如加工單價)不存在時安全回 null
                $v = $row[$idx] ?? null;
                if ($v === null) return null;
                $v = trim((string)$v);
                return ($v === '' || strtoupper($v) === 'NULL') ? null : $v;
            };

            $bom    = $g('製令單號');
            $bom_sn = $g('生產序號');
            if ($bom === null || !is_numeric($bom_sn)) continue; // 空白/小計列

            $process_no = $g('製程代號');
            if ($process_no === null || !is_numeric($process_no)) { $stats['skip_no_process']++; continue; }
            $maker_id_no = $g('生產單位');
            if ($maker_id_no === null) { $stats['skip_no_maker']++; continue; }

            $inDate  = transferErpRocDate($g('預移入日'));
            $outDate = transferErpRocDate($g('預移出日'));
            // 無效資料排除：預移入/出日為 1911/1/1 空值佔位、或民國年異常（如 180）→ 直接擋掉
            if (!transferErpValidBizDate($inDate) || !transferErpValidBizDate($outDate)) { $stats['skip_invalid_date']++; continue; }
            // 非當天移送：移入≠移出（同 VBA「移入移出日期不同者刪除」）
            if ($inDate !== $outDate) { $stats['skip_date_diff']++; continue; }

            $item = [
                'bom'          => $bom,
                'bom_sn'       => (int)$bom_sn,
                'process_no'   => (int)$process_no,
                'maker_id_no'  => $maker_id_no,
                'sqty'         => is_numeric($g('生產數量')) ? (int)$g('生產數量') : null,
                'move_date'    => $inDate,               // 預移入日＝實際移入日（進 outsource_date/Created_At）
                'ps'           => bomErpCleanPs($g('備註')),
                'changer_name' => $g('異動人員'),
                'changed_ts'   => transferErpRocDate($g('異動日期時間')) ?? '0000-00-00 00:00:00',
                'transfer_no'  => $g('加工單號'),        // I-系列加工單號，判定重複匯入vs重工覆蓋用
                'unit_price'   => is_numeric($g('加工單價')) ? (float)$g('加工單價') : 0, // Phase C 發包單價
            ];
            $key = $bom . '|' . $item['bom_sn'];
            if (isset($byKey[$key])) {
                $stats['dedup_dropped']++;
                if ($item['changed_ts'] > $byKey[$key]['changed_ts']) $byKey[$key] = $item;
            } else {
                $byKey[$key] = $item;
            }
        }
        return array_values($byKey);
    }
}

// ── 入口①：AJAX 預覽 ──────────────────────────────────────────────────────
if (isset($_GET['but']) && $_GET['but'] === 'Transfer_ERP_Preview') {
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(120);
    ini_set('memory_limit', '512M');

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => '請選擇要上傳的檔案']); exit;
    }

    try {
        $spreadsheet = IOFactory::load($_FILES['file']['tmp_name']);
        $allRows     = $spreadsheet->getActiveSheet()->toArray();
        $stats       = [];
        $rows        = parseTransferErpRows($allRows, $stats);

        if (empty($rows)) {
            echo json_encode(['success' => false, 'message' => '沒有符合條件的移轉列（需製程/生產單位非空、預移入日=預移出日），請確認是否為 ERP 移轉原始檔']); exit;
        }

        // 既有 bom_ing 檢查（用 Phase B 覆蓋判定分類）＋ bom 主檔存在檢查
        $insertCount = 0; $writeCount = 0; $skipDupCount = 0; $skipStaleCount = 0;
        $staleSamples = [];
        $missingBoms = [];
        $ingChk = $db->prepare("SELECT transfer_no, transfer_changed_at, QC_check, return_date, qc_completed
                                FROM bom_ing WHERE bom = ? AND bom_sn = ?");
        $bomChk = $db->prepare("SELECT bom FROM bom WHERE bom = ?");
        $bomSeen = [];
        foreach ($rows as &$row) {
            $ingChk->execute([$row['bom'], $row['bom_sn']]);
            $existing = $ingChk->fetch(PDO::FETCH_ASSOC);
            $action = transferErpClassify($existing, $row['transfer_no'], $row['changed_ts']);
            $row['action'] = $action;
            if ($action === 'insert')          $insertCount++;
            elseif ($action === 'write')       $writeCount++;
            elseif ($action === 'skip_dup')    $skipDupCount++;
            elseif ($action === 'skip_stale') { $skipStaleCount++; if (count($staleSamples) < 5) $staleSamples[] = $row['bom'] . '-' . $row['bom_sn']; }
            if (!isset($bomSeen[$row['bom']])) {
                $bomChk->execute([$row['bom']]);
                $bomSeen[$row['bom']] = (bool)$bomChk->fetchColumn();
                if (!$bomSeen[$row['bom']]) $missingBoms[] = $row['bom'];
            }
        }
        unset($row);

        // 廠商/使用者對照（顯示用）
        $makerNames = [];
        foreach ($db->query("SELECT maker_id_no, maker_id FROM maker_list")->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $makerNames[$m['maker_id_no']] = $m['maker_id'];
        }
        $unknownMakers = [];
        foreach ($rows as $row) {
            if (!isset($makerNames[$row['maker_id_no']])) $unknownMakers[$row['maker_id_no']] = true;
        }

        $warnings = [];
        if ($stats['skip_no_process'] > 0)   $warnings[] = "製程代號空白跳過 {$stats['skip_no_process']} 列";
        if ($stats['skip_no_maker'] > 0)     $warnings[] = "生產單位空白跳過 {$stats['skip_no_maker']} 列";
        if ($stats['skip_invalid_date'] > 0) $warnings[] = "無效日期資料(1911/1/1空值或民國年異常)跳過 {$stats['skip_invalid_date']} 列";
        if ($stats['skip_date_diff'] > 0)    $warnings[] = "預移入/移出日不同(未完成移轉)跳過 {$stats['skip_date_diff']} 列";
        if ($stats['dedup_dropped'] > 0)   $warnings[] = "同製令+序號重複 {$stats['dedup_dropped']} 列，只取異動時間最新一筆";
        if ($skipDupCount > 0)             $warnings[] = "同加工單號、已匯入過 {$skipDupCount} 列 → 跳過（不重複覆蓋）";
        if ($skipStaleCount > 0)           $warnings[] = "偵測到 {$skipStaleCount} 列疑似舊檔/較舊移轉（已有較新進度或檢驗）→ 跳過保護，避免回捲洗掉資料" . (!empty($staleSamples) ? '（例：' . implode('、', $staleSamples) . '）' : '');
        if (!empty($unknownMakers))        $warnings[] = '生產單位查無廠商主檔（' . implode('、', array_keys($unknownMakers)) . '），maker_id 簡稱將留空';
        if (!empty($missingBoms))          $warnings[] = 'BOM 主檔不存在（' . implode('、', $missingBoms) . '），製程列仍會建立，建議先執行 BOM ERP匯入';

        $previewRows = [];
        $actionLabel = ['insert' => '新增', 'write' => '覆蓋更新', 'skip_dup' => '重複跳過', 'skip_stale' => '舊檔跳過'];
        foreach (array_slice($rows, 0, 10) as $row) {
            $previewRows[] = [
                'bom'         => $row['bom'],
                'bom_sn'      => $row['bom_sn'],
                'process_no'  => $row['process_no'],
                'maker_id_no' => $row['maker_id_no'],
                'maker_name'  => $makerNames[$row['maker_id_no']] ?? null,
                'sqty'        => $row['sqty'],
                'move_date'   => substr($row['move_date'], 0, 10),
                'action'      => $actionLabel[$row['action']] ?? $row['action'],
            ];
        }

        $_SESSION['transfer_erp_rows'] = $rows;
        $_SESSION['transfer_erp_ts']   = time();

        echo json_encode([
            'success'        => true,
            'total_rows'     => count($rows),
            'insert_count'   => $insertCount,
            'write_count'    => $writeCount,
            'skip_dup_count' => $skipDupCount,
            'skip_stale_count' => $skipStaleCount,
            'warnings'       => $warnings,
            'preview_rows'   => $previewRows,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '解析失敗：' . $e->getMessage()]);
    }
    exit;
}

// ── 入口②：AJAX 確認匯入 — 鏡像 u5 更新語意，transaction 全有全無 ────────
if (isset($_GET['but']) && $_GET['but'] === 'Transfer_ERP_Commit') {
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(300);
    ini_set('memory_limit', '512M');

    if (!isset($_SESSION['transfer_erp_rows']) || time() - (int)($_SESSION['transfer_erp_ts'] ?? 0) > 1800) {
        echo json_encode(['success' => false, 'message' => '預覽資料已過期（超過30分鐘），請重新上傳檔案']); exit;
    }

    $rows = $_SESSION['transfer_erp_rows'];
    unset($_SESSION['transfer_erp_rows'], $_SESSION['transfer_erp_ts']);

    try {
        $db->beginTransaction();

        $makerNames = [];
        foreach ($db->query("SELECT maker_id_no, maker_id FROM maker_list")->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $makerNames[$m['maker_id_no']] = $m['maker_id'];
        }

        // 異動人員姓名 → user.id（查無→99991，同 transfer_log_raw；取代 VBA 寫死的三位人名替換）
        $userStmt  = $db->prepare("SELECT id FROM user WHERE user_cname = ?");
        $userCache = [];
        $unknownChangers = [];
        $lookupUser = function ($cname) use ($userStmt, &$userCache, &$unknownChangers) {
            if ($cname === null) return 99991;
            if (!isset($userCache[$cname])) {
                $userStmt->execute([$cname]);
                $uid = $userStmt->fetchColumn();
                $userCache[$cname] = $uid ?: 99991;
                if (!$uid) $unknownChangers[$cname] = true;
            }
            return $userCache[$cname];
        };

        // Phase B：抓判定用欄位（transfer_no/transfer_changed_at/QC_check/return_date/qc_completed）
        $ingChk = $db->prepare("SELECT bom_ing_fid, transfer_no, transfer_changed_at, QC_check, return_date, qc_completed
                                FROM bom_ing WHERE bom = ? AND bom_sn = ?");
        // 覆蓋（write）：連未帶值欄位一併清 NULL（同 u5「重新開始一道製程」語意），QC_ps 不動；
        // 並寫入 transfer_no/transfer_changed_at 供下次判定。
        $updStmt = $db->prepare("UPDATE bom_ing SET
                machine_id = NULL, process_no = ?, maker_id_no = ?, maker_id = ?, sqty = ?,
                processing_sequence = NULL, processing_state = 'ing', QC_check = NULL, QC_check_date = NULL,
                ps = ?, outsource_date = ?, return_date = NULL, Delivery_date = NULL, PS2 = NULL, 1_side = NULL,
                transfer_no = ?, transfer_changed_at = ?,
                Created_By = ?, Created_At = ?, Modified_By = NULL, Modified_At = NULL
                WHERE bom_ing_fid = ?");
        $insStmt = $db->prepare("INSERT INTO bom_ing
                (bom_ing_id, bom, process_no, maker_id_no, maker_id, sqty, bom_sn,
                 processing_state, ps, outsource_date, transfer_no, transfer_changed_at, Created_By, Created_At)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'ing', ?, ?, ?, ?, ?, ?)");

        // 註：Phase C（當天移轉順手寫發包單價進 bom_ing_transfer_log）已撤回。
        // 原因：當天移轉是「加工單號 I 系列」一單一列，月結是「單號 J 系列」一單可多列（一張加工單被拆多筆移轉），
        // 兩者顆粒度不同會在同一 (bom,bom_sn) 下並存；成本分析(Order_Profit_Analysis)對 (bom,bom_sn) 算加權平均，
        // 會把當天發包估價與月結最終價混在一起平均而失真。早填成本需另設計，勿在此重啟。
        $inserted = 0; $written = 0; $skippedDup = 0; $skippedStale = 0;
        $staleSamples = [];
        foreach ($rows as $row) {
            $makerId = $makerNames[$row['maker_id_no']] ?? null;
            $userId  = $lookupUser($row['changer_name']);

            $ingChk->execute([$row['bom'], $row['bom_sn']]);
            $existing = $ingChk->fetch(PDO::FETCH_ASSOC);
            $action   = transferErpClassify($existing, $row['transfer_no'], $row['changed_ts']);

            if ($action === 'insert') {
                $bomIngId = substr($row['bom'], -9) . '-' . $row['process_no'] . '-' . $row['bom_sn'] . '-' . $row['sqty'] . '-' . $row['maker_id_no'];
                $insStmt->execute([
                    $bomIngId, $row['bom'], $row['process_no'], $row['maker_id_no'], $makerId,
                    $row['sqty'], $row['bom_sn'], $row['ps'], $row['move_date'],
                    $row['transfer_no'], $row['changed_ts'], $userId, $row['move_date'],
                ]);
                $inserted++;
            } elseif ($action === 'write') {
                $updStmt->execute([
                    $row['process_no'], $row['maker_id_no'], $makerId, $row['sqty'],
                    $row['ps'], $row['move_date'], $row['transfer_no'], $row['changed_ts'],
                    $userId, $row['move_date'], $existing['bom_ing_fid'],
                ]);
                $written++;
            } elseif ($action === 'skip_dup') {
                $skippedDup++;   // 同加工單號、已匯入過：bom_ing 不動（價格仍下方補）
            } else { // skip_stale
                $skippedStale++;
                if (count($staleSamples) < 5) $staleSamples[] = $row['bom'] . '-' . $row['bom_sn'];
            }
        }

        recordUploadLog($db, 'upload_bom_ing_s_erp');
        $db->commit();

        $msg = "移轉匯入完成！新增 {$inserted} 筆、覆蓋更新 {$written} 筆";
        if ($skippedDup > 0)   $msg .= "、重複跳過 {$skippedDup} 筆";
        if ($skippedStale > 0) $msg .= "、舊檔保護跳過 {$skippedStale} 筆" . (!empty($staleSamples) ? '（例：' . implode('、', $staleSamples) . '）' : '');
        if (!empty($unknownChangers)) {
            $msg .= "。⚠ 異動人員 [" . implode('、', array_keys($unknownChangers)) . "] 查無此人，以預設人員(99991)記錄";
        }
        echo json_encode([
            'success' => true,
            'inserted' => $inserted, 'written' => $written,
            'skipped_dup' => $skippedDup, 'skipped_stale' => $skippedStale,
            'message' => $msg,
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['success' => false, 'message' => '匯入失敗（資料未寫入）：' . $e->getMessage()]);
    }
    exit;
}