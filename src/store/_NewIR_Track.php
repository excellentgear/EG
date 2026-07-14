<?php
// _NewIR_Track.php
session_start();
include_once dirname(__DIR__) . '/common/DBConnection.php';
include_once dirname(__DIR__) . '/common/_config.php';

// 啟用錯誤顯示 (建議在正式環境關閉)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 檢查是否為 AJAX 請求
function is_ajax() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}

// 如果不是 AJAX 請求，則不處理
if (!is_ajax()) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

try {
    if (isset($_POST['or_new']) || isset($_POST['or_new_copy']) || isset($_POST['or_update'])) {
        if (isset($_POST['or_update'])) {
            // ----- 更新操作 -----
            // 這裡的邏輯是直接更新，不再處理複雜的交期變更歷史
            $sql = "UPDATE `ir_track` SET 
                    `IR_no` = :IR_no,
                    `d_id` = :d_id,
                    `Processing_items` = :Processing_items,
                    `IR_ps` = :IR_ps,
                    `Client_name` = :Client_Name,
                    `Qty` = :Qty,
                    `IR_date` = :IR_date,
                    `C_IR` = :C_IR,
                    `QC_Assignee` = :QC_Assignee,
                    Modified_By = :Modified_By,
                    Modified_At = NOW()
                    WHERE `IR_id` = :IR_id";
            $stmt = $db->prepare($sql);

            $stmt->bindParam(':IR_no', $_POST['IR_no']);
            $stmt->bindParam(':d_id', $_POST['d_id']);
            $stmt->bindParam(':IR_ps', $_POST['IR_ps']);
            $stmt->bindParam(':Processing_items', $_POST['Processing_items']);
            $stmt->bindParam(':Client_Name', $_POST['Client_Name']);
            $stmt->bindParam(':Qty', $_POST['Qty']);
            $stmt->bindParam(':IR_date', $_POST['IR_date']);
            $stmt->bindParam(':C_IR', $_POST['C_IR']);
            $stmt->bindParam(':QC_Assignee', $_POST['QC_Assignee']);
            $stmt->bindParam(':IR_id', $_POST['IR_id']);
            $stmt->bindParam(':Modified_By', $_SESSION['id']);

            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => '退貨單更新成功。']);
            } else {
                echo json_encode(['success' => false, 'message' => '更新失敗或資料無變更。']);
            }
            exit; // AJAX 請求處理完畢後退出
        } else {
            // ----- 新增 / 複製新增操作 -----
            $sql = "INSERT INTO `ir_track` SET
                    IR_id = NULL,
                    `IR_no` = :IR_no,
                    `d_id` = :d_id,
                    `Processing_items` = :Processing_items,
                    `IR_ps` = :IR_ps,
                    `Client_name` = :Client_Name,
                    `Qty` = :Qty,
                    `IR_date` = :IR_date,
                    `C_IR` = :C_IR,
                    `QC_Assignee` = :QC_Assignee,
                    `IR_status` = NULL,
                    `Created_At` = NOW(),
                    `Created_By` = :Created_By";

            $stmt = $db->prepare($sql);

            $stmt->bindParam(':IR_no', $_POST['IR_no']);
            $stmt->bindParam(':d_id', $_POST['d_id']);
            $stmt->bindParam(':Processing_items', $_POST['Processing_items']);
            $stmt->bindParam(':Client_Name', $_POST['Client_Name']);
            $stmt->bindParam(':Qty', $_POST['Qty']);
            $stmt->bindParam(':IR_date', $_POST['IR_date']);
            $stmt->bindParam(':C_IR', $_POST['C_IR']);
            $stmt->bindParam(':QC_Assignee', $_POST['QC_Assignee']);
            $stmt->bindParam(':IR_ps', $_POST['IR_ps']);
            $stmt->bindParam(':Created_By', $_SESSION['id']);

            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => '退貨單新增成功。']);
            } else {
                echo json_encode(['success' => false, 'message' => '新增失敗。']);
            }
            exit; // AJAX 請求處理完畢後退出
        }
    }

    if (isset($_POST['resetpSetting'])) {
        // 這個操作應該由前端處理，後端不需要做任何事
        echo json_encode(['success' => true, 'message' => '清除操作已由前端處理。']);
        exit;
    }

    if (isset($_POST['del_ir_track'])) {
        // 刪除操作應有更嚴格的權限驗證，此處暫不實現
        echo json_encode(['success' => false, 'message' => '刪除功能尚未實現。']);
        exit;
    }
} catch (PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['success' => false, 'message' => '資料庫錯誤：' . $e->getMessage()]);
    exit;
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['success' => false, 'message' => '一般錯誤：' . $e->getMessage()]);
    exit;
}


?>
