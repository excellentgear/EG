<?php
session_start();
include_once '../common/DBConnection.php'; // Make sure this path is correct
include '../common/_config.php';      // Make sure this path is correct

header('Content-Type: application/json');
$response = ['success' => false, 'message' => ''];

if (!isset($_SESSION['id'])) {
    $response['message'] = '使用者未登入。';
    echo json_encode($response);
    exit;
}

$userId = $_SESSION['id'];

if (
    isset($_POST['bom']) && !empty(trim($_POST['bom'])) &&
    isset($_POST['new_sn']) && trim($_POST['new_sn']) !== '' &&
    isset($_POST['new_process_no']) && trim($_POST['new_process_no']) !== ''
) {
    $bom = trim($_POST['bom']);
    $new_sn = trim($_POST['new_sn']);
    $new_process_no = trim($_POST['new_process_no']);
    $sqty = isset($_POST['sqty']) && is_numeric($_POST['sqty']) ? (int)$_POST['sqty'] : null;

    // Generate bom_ing_id
    $bom_ing_id = $bom . '-' . $new_sn;

    try {
        // Server-side validation for uniqueness within the same BOM
        $checkSql = "SELECT COUNT(*) FROM bom_ing WHERE bom = :bom AND (bom_sn = :new_sn OR process_no = :new_process_no)";
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->bindParam(':bom', $bom, PDO::PARAM_STR);
        $checkStmt->bindParam(':new_sn', $new_sn, PDO::PARAM_STR); // bom_sn can be alphanumeric
        $checkStmt->bindParam(':new_process_no', $new_process_no, PDO::PARAM_INT);
        $checkStmt->execute();
        if ($checkStmt->fetchColumn() > 0) {
            $response['message'] = '此 BOM 已存在相同的 SN 或製程代號。';
            echo json_encode($response);
            exit;
        }

        $insertSql = "INSERT INTO bom_ing (
                        bom_ing_id, bom, bom_sn, process_no, sqty, 
                        processing_state, processing_sequence, Created_By, Created_At, Modified_By, Modified_At
                      ) VALUES (
                        :bom_ing_id, :bom, :bom_sn, :process_no, :sqty,
                        'N', :processing_sequence, :created_by, NOW(), :modified_by, NOW()
                      )";
        
        $stmt = $db->prepare($insertSql);

        $stmt->bindParam(':bom_ing_id', $bom_ing_id, PDO::PARAM_STR);
        $stmt->bindParam(':bom', $bom, PDO::PARAM_STR);
        $stmt->bindParam(':bom_sn', $new_sn, PDO::PARAM_STR); // bom_sn can be alphanumeric
        $stmt->bindParam(':process_no', $new_process_no, PDO::PARAM_INT);
        $stmt->bindParam(':sqty', $sqty, PDO::PARAM_INT); // This is the BOM's total quantity, not specific to this process step
        $stmt->bindParam(':processing_sequence', $new_sn, PDO::PARAM_INT); // Assuming bom_sn is the sequence
        $stmt->bindParam(':created_by', $userId, PDO::PARAM_STR);
        $stmt->bindParam(':modified_by', $userId, PDO::PARAM_STR);

        if ($stmt->execute()) {
            if ($stmt->rowCount() > 0) {
                $response['success'] = true;
                $response['message'] = '製程新增成功！';

                // Fetch the ProcessName for the newly added process_no
                $processName = 'N/A'; // Default if not found
                if ($new_process_no !== null) {
                    $fetchProcessSql = "SELECT ProcessName FROM process_no WHERE ProcessNo = :process_no_id";
                    $fetchProcessStmt = $db->prepare($fetchProcessSql);
                    $fetchProcessStmt->bindParam(':process_no_id', $new_process_no, PDO::PARAM_INT);
                    if ($fetchProcessStmt->execute()) {
                        $processData = $fetchProcessStmt->fetch(PDO::FETCH_ASSOC);
                        if ($processData && isset($processData['ProcessName'])) {
                            $processName = $processData['ProcessName'];
                        }
                    }
                }
                $response['inserted_data'] = [
                    'bom_ing_id' => $bom_ing_id,
                    'process_name' => $processName,
                    'process_no' => $new_process_no
                ];
            } else {
                $response['message'] = '新增製程失敗，沒有資料列被影響。';
            }
        } else {
            $errorInfo = $stmt->errorInfo();
            $response['message'] = '資料庫錯誤：' . $errorInfo[2];
        }
    } catch (PDOException $e) {
        $response['message'] = '資料庫操作異常：' . $e->getMessage();
    }
} else {
    $response['message'] = '缺少必要的參數 (BOM, SN, 或 製程代號)。';
}

echo json_encode($response);
exit;
?>