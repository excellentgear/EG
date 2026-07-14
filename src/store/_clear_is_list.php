<?php
session_start();
header('Content-Type: application/json');

// Basic security check
if (!isset($_SESSION['id'])) {
    echo json_encode(['success' => false, 'message' => '使用者未登入。']);
    exit;
}

include '../../src/common/DBConnection.php';
include '../../src/common/_config.php';

$response = ['success' => false, 'message' => '', 'deleted_rows' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 修改：不再需要 end_month，只檢查 start_month
    if (isset($_POST['start_month'])) {
        $startMonth = filter_input(INPUT_POST, 'start_month', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);

        if ($startMonth === false) {
            $response['message'] = '無效的月份參數。';
        } else {
            try {
                // 修改：起始日期計算方式
                // 使用操作當下的年月作為基準
                // 修改：直接使用當前年份，移除日期判斷
                $yearToClear = date('Y');

                // 建立要清除的起始日期 (該月第一天)
                $startDate = date('Y-m-d', strtotime("{$yearToClear}-{$startMonth}-01"));

                // 修改：SQL 查詢邏輯，刪除大於或等於起始日期的所有資料
                $sql = "DELETE FROM `is_list` WHERE `Order_date` >= :start_date";
                $stmt = $db->prepare($sql);
                $stmt->bindParam(':start_date', $startDate, PDO::PARAM_STR);
                
                $stmt->execute();
                
                $deletedRows = $stmt->rowCount();

                $response['success'] = true;
                $response['message'] = "成功刪除 {$deletedRows} 筆記錄。";
                $response['deleted_rows'] = $deletedRows;

            } catch (PDOException $e) {
                $response['message'] = '資料庫錯誤：' . $e->getMessage();
            }
        }
    } else {
        $response['message'] = '缺少必要的參數。';
    }
} else {
    $response['message'] = '無效的請求方法。';
}

echo json_encode($response);
?>