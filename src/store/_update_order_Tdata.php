<?php
// _update_order_data.php

// 開啟錯誤回報（除錯階段）
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 載入必要的設定與資料庫連線檔案
include '../../src/common/DBConnection.php';
include '../../src/common/_config.php';

// 檢查是否有正確傳入所需參數
if (!isset($_POST['orderId']) || !isset($_POST['noteType']) || !isset($_POST['note'])) {
    die("缺少必要的更新參數");
}

$orderId  = trim($_POST['orderId']);
$noteType = $_POST['noteType'];
$note     = $_POST['note'];

// 僅允許更新業務備註 (Order_ps) 或 設計備註 (ateNote)
if ($noteType !== "Order_ps" && $noteType !== "ateNote") {
    die("不合法的 noteType");
}

// 準備 SQL 語法，更新 order_track 資料表中對應的欄位
$query = "UPDATE order_track SET {$noteType} = :note WHERE Order_id = :orderId";
$stmt = $db->prepare($query);
if (!$stmt) {
    $errorInfo = $db->errorInfo();
    die("Prepare 失敗: " . $errorInfo[2]);
}

// 綁定參數
if (is_numeric($orderId)) {
    $stmt->bindValue(':orderId', $orderId, PDO::PARAM_INT);
} else {
    $stmt->bindValue(':orderId', $orderId, PDO::PARAM_STR);
}
$stmt->bindValue(':note', $note, PDO::PARAM_STR);

// 執行更新
if ($stmt->execute()) {
    $rowCount = $stmt->rowCount();
    if ($rowCount > 0) {
        echo "更新成功";
    } else {
        echo "查無更新紀錄或資料相同";
    }
} else {
    $errorInfo = $stmt->errorInfo();
    echo "更新失敗: " . $errorInfo[2];
}
?>
