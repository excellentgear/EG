<?php
session_start();

include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../../vendor/autoload.php';  // 確認引用正確路徑
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

// 更新報工_包含tg 2025.02.21 OK
    function quoteValue($value) {
        if (is_null($value) || $value === '' || strtoupper($value) === 'NULL') {
            return null;
        } else {
            return $value;
        }
    }
    
    function convertDateFormat($date) {
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
    
        // 跳過標頭行
        $data = array_slice($data, 1);
    
        // 插入數據
        $stmt = $db->prepare("INSERT INTO `reply_all`(`reply_id`, `BOM`, `bom_ing_id`, `ps`, `Client_Name`, `sqty`, `oready_sqty`, `ProcessNo`, `MakerId`, `ok_sqty`, `ng_sqty`, `ng_id`, `ng_sqty2`, `ng_id2`, `ng_sqty3`, `ng_id3`, `m`, `t`, `width`, `mc_id`, `mc_time`, `machine_id`, `change_tool`, `processing_time`, `completed`, `Created_By`, `Created_At`, `Modified_By`, `Modified_At`)
        VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM reply_all WHERE bom_ing_id = ? AND oready_sqty = ? AND MakerId = ? AND ok_sqty = ?");
    
        $SUSS_MAG = "";
        $successCount = 0;
    
        foreach ($data as $row) {
            // 確保每行數據的長度不超過20格
            if (count($row) > 29) {
                $row = array_slice($row, 0, 28);
            } elseif (count($row) == 29) {
                $row = array_slice($row, 1);
            }
    
            // 轉換日期格式
            if (isset($row[17])) {
                $row[17] = convertDateFormat($row[17]);
            }
            if (isset($row[19])) {
                $row[19] = convertDateFormat($row[19]);
            }
    
            // 將值轉換為適當的格式
            $quotedRow = array_map('quoteValue', $row);
    
            if (count($quotedRow) == 28) {
                // 顯示檢查語句及其參數
                echo "正在檢查的資料：bom_ing_id = {$quotedRow[1]}, oready_sqty = {$quotedRow[5]}, MakerId = {$quotedRow[7]}, ok_sqty = {$quotedRow[8]}<br>";
                $checkStmt->execute([$row[1], $row[5], $row[7], $row[8]]);
    
                if ($checkStmt->fetchColumn() > 0) {
                    $SUSS_MAG .= $row[0] . " 資料已存在未輸入\n";
                } else {
                    // 插入數據
                    $stmt->execute($quotedRow);
                    $successCount++;
                }
            } else {
                echo "跳過一行數據，由於列數不匹配: ";
                print_r($row);
            }
        }
    
        $SUSS_MAG = "成功輸入 " . $successCount . " 筆資料\n" . $SUSS_MAG;
        echo nl2br($SUSS_MAG);
    
        // URL 編碼訊息
        $encodedMessage = urlencode($SUSS_MAG);
        header("location:reply_other.php?message=oth&msg=".$encodedMessage);
    } else {
        echo "請上傳一個Excel文件";
    }

?>