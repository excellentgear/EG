<?php
session_start();
include '../common/DBConnection.php'; // 確保路徑正確
include '_setting.php'; // 確保路徑正確
include '../common/_config.php'; // 確保路徑正確

header('Content-Type: application/json');

$response = ['success' => false, 'message' => '未知錯誤'];

if (!isset($_SESSION['userName'])) {
    $response['message'] = '用戶未登入';
    echo json_encode($response);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bom = $_POST['bom'] ?? null;
    $bom_ing_id = $_POST['bom_ing_id'] ?? null; // 用於更新 bom_ing
    $new_client_name = $_POST['client_name'] ?? null;
    $new_process_no = $_POST['process_no'] ?? null;
    $new_order_id_from_frontend = $_POST['order_id'] ?? null; // 新增：接收訂單ID
    // $new_process_name = $_POST['process_name'] ?? null; // 如果有傳遞 ProcessName

    if (!$bom || !$bom_ing_id) { // 客戶和製程可以是空字串，訂單ID也可以是空（代表未選取）
        $response['message'] = '缺少 BOM 或 bom_ing_id 參數';
        echo json_encode($response);
        exit();
    }

    try {
        $db->beginTransaction();

        // 處理訂單ID，如果是 'B' (備庫) 或空字串 (未選取)，則在資料庫中存為 NULL
        $actual_order_id_for_db = null;
        if ($new_order_id_from_frontend === 'B' || $new_order_id_from_frontend === '') {
            $actual_order_id_for_db = null;
        } else if (filter_var($new_order_id_from_frontend, FILTER_VALIDATE_INT)) {
            $actual_order_id_for_db = (int)$new_order_id_from_frontend;
        }

        // 1. 更新 bom 表的 Client_Name 和 o_order_id
        $stmt_bom = $db->prepare("UPDATE bom SET Client_Name = :client_name, o_order_id = :order_id, Modified_At = NOW(), Modified_By = :user_id WHERE bom = :bom");
        $stmt_bom->bindParam(':client_name', $new_client_name, PDO::PARAM_STR);
        $stmt_bom->bindParam(':order_id', $actual_order_id_for_db, $actual_order_id_for_db === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt_bom->bindParam(':user_id', $_SESSION['id'], PDO::PARAM_INT);
        $stmt_bom->bindParam(':bom', $bom, PDO::PARAM_STR);
        $stmt_bom->execute();

        // 2. 更新 bom_ing 表的 process_no (以及可能的 maker_id, ProcessName 等)
        // 注意：如果 process_no 改變，通常 maker_id 也可能需要清空或根據新的製程邏輯更新
        // 這裡僅更新 process_no，您可以根據實際需求擴展
        $stmt_bom_ing = $db->prepare("UPDATE bom_ing SET process_no = :process_no, Modified_At = NOW(), Modified_By = :user_id WHERE bom_ing_id = :bom_ing_id");
        $stmt_bom_ing->bindParam(':process_no', $new_process_no, PDO::PARAM_STR);
        $stmt_bom_ing->bindParam(':user_id', $_SESSION['id'], PDO::PARAM_INT);
        $stmt_bom_ing->bindParam(':bom_ing_id', $bom_ing_id, PDO::PARAM_STR); // bom_ing_id 通常是字串
        $stmt_bom_ing->execute();

        $db->commit();
        $response['success'] = true;
        $response['message'] = '資料更新成功';

    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $response['message'] = '資料庫更新失敗：' . $e->getMessage();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $response['message'] = '發生錯誤：' . $e->getMessage();
    }
} else {
    $response['message'] = '無效的請求方法';
}

echo json_encode($response);
?>