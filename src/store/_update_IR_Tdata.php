<?php
// _update_IR_Tdata.php

// 開啟錯誤回報（除錯階段）
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 載入必要的設定與資料庫連線檔案
include '../../src/common/DBConnection.php';
include '../../src/common/_config.php';

// 檢查是否有正確傳入所需參數
if (!isset($_POST['irId']) || !isset($_POST['noteType']) || !isset($_POST['note'])) {
    die("缺少必要的更新參數 (irId, noteType, note)");
}

$irId     = trim($_POST['irId']);
$noteType = $_POST['noteType'];
$note     = $_POST['note'];

// 建立資料庫連線
$conn = new DBConnection();
$db = $conn->getPDO();

// 僅允許更新指定的備註欄位
$allowedNoteTypes = ["IR_ps", "qcNote", "ateNote", "pmNote", "bossNote", "closeNote", "seles_Note"];
if (!in_array($noteType, $allowedNoteTypes)) {
    die("不合法的 noteType: " . htmlspecialchars($noteType));
}

// 準備 SQL 語法，更新 IR_track 資料表中對應的欄位
$query = "UPDATE `IR_track` SET `{$noteType}` = :note, `Modified_At` = NOW() WHERE `IR_id` = :irId";
$stmt = $db->prepare($query);
if (!$stmt) {
    $errorInfo = $db->errorInfo();
    die("Prepare 失敗: " . $errorInfo[2]);
}

// 綁定參數
$stmt->bindValue(':note', $note, PDO::PARAM_STR);
$stmt->bindValue(':irId', $irId, PDO::PARAM_INT);

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
