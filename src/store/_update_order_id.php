<?php
if (!isset($_SESSION)) {
    session_start();
}

include '../../src/common/DBConnection.php';
include '../../src/common/_config.php';

header('Content-Type: application/json'); // 設定回應類型為 JSON

if (isset($_POST['Order_id']) && isset($_POST['bom'])) { // 檢查 'bom' 而不是 'bom_ing_fid'
    $orderIdFromPost = $_POST['Order_id']; // Can be "", "B", or an actual ID
    $bomValue = $_POST['bom']; // 獲取 BOM 編號

    $orderIdToStore = null; // Default to NULL (for "請選擇")
    if ($orderIdFromPost === 'B') {
        $orderIdToStore = 'B'; // If "備庫" is selected, store 'B'
    } elseif (!empty($orderIdFromPost) && $orderIdFromPost !== 'B') { // If not 'B' and not empty, it's an actual Order_id
        $orderIdToStore = $orderIdFromPost;
    }
    // If $orderIdFromPost is an empty string "", $orderIdToStore remains null.

    try {
        // 更新 bom 表的 o_order_id 欄位
        $stmt = $db->prepare("UPDATE bom SET o_order_id = :order_id WHERE bom = :bom_value");
        $stmt->bindParam(':order_id', $orderIdToStore, ($orderIdToStore === null) ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindParam(':bom_value', $bomValue, PDO::PARAM_STR); // BOM 編號通常是字串

        if ($stmt->execute()) {
            // 可以選擇性地查詢並返回新的訂單資訊，供前端備用
            // $newOrderInfo = $db->...

            echo json_encode(['success' => true, 'message' => 'BOM 主訂單更新成功']);
        } else {
            $errorInfo = $stmt->errorInfo();
            error_log("BOM o_order_id update failed for BOM: {$bomValue}. Error: " . $errorInfo[2]);
            echo json_encode(['success' => false, 'message' => '資料庫更新失敗 (BOM o_order_id)']);
        }
    } catch (PDOException $e) {
        error_log("Database error in _update_order_id.php (updating bom.o_order_id): " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '資料庫錯誤: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => '缺少必要參數']);
}
exit; // 確保沒有其他輸出
?>