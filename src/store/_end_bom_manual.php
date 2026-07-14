<?php
// _end_bom_manual.php
session_start();

// Basic security check
if (!isset($_SESSION['userName'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => '未授權的操作。']);
    exit;
}

include '../../src/common/DBConnection.php';
include '../../src/common/_config.php';

header('Content-Type: application/json');
ob_clean(); // Clean any previous output

$response = ['success' => false, 'message' => ''];

if (!isset($db)) {
    $response['message'] = '資料庫連線失敗。';
    echo json_encode($response);
    exit;
}

if (!isset($_POST['bom']) || empty(trim($_POST['bom']))) {
    $response['message'] = '錯誤：缺少 BOM 參數。';
    echo json_encode($response);
    exit;
}

$bom_to_update = trim($_POST['bom']);
$operator_id   = isset($_SESSION['id']) && is_numeric($_SESSION['id']) ? (int)$_SESSION['id'] : null;
$modified_by   = $_SESSION['id'] ?? 'system_manual_close';

try {
    $stmt = $db->prepare("UPDATE bom
            SET processing_state = '1',
                bom_ing_id       = '1',
                closed_by        = :closed_by,
                closed_at        = NOW(),
                Modified_At      = NOW(),
                Modified_By      = :modified_by
            WHERE bom = :bom");
    $stmt->bindValue(':closed_by',   $operator_id,   $operator_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindParam(':modified_by', $modified_by,   PDO::PARAM_STR);
    $stmt->bindParam(':bom',         $bom_to_update, PDO::PARAM_STR);

    if ($stmt->execute()) {
        if ($stmt->rowCount() > 0) {
            // 寫入操作流水帳
            try {
                $logStmt = $db->prepare("INSERT INTO bom_operation_log (bom, operation_type, operator_id, details_json) VALUES (?, 'manual_close', ?, ?)");
                $logStmt->execute([$bom_to_update, $operator_id, json_encode(['操作' => '人工結案'])]);
            } catch (PDOException $le) {
                error_log("bom_operation_log insert error: " . $le->getMessage());
            }
            $response['success'] = true;
            $response['message'] = 'BOM ' . htmlspecialchars($bom_to_update) . ' 已手動結案。';
        } else {
            $response['message'] = '結案失敗：找不到指定的 BOM 或資料無變更。';
        }
    } else {
        $errorInfo = $stmt->errorInfo();
        $response['message'] = '資料庫更新失敗：' . $errorInfo[2];
        error_log("Manual BOM close error: " . $errorInfo[2]);
    }

} catch (PDOException $e) {
    $response['message'] = '資料庫操作錯誤：' . $e->getMessage();
    error_log("Manual BOM close PDOException: " . $e->getMessage());
}

echo json_encode($response);
exit;
?>