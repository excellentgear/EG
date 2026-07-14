<?php
header('Content-Type: application/json');
require_once '../common/_config.php'; // 視你的資料庫連線檔案
if (!isset($db)) {
    // 簡單fallback
    include_once '../common/DBConnection.php';
    $db = (new DBConnection())->getDB();
}

$bi = $_GET['bi'] ?? '';
if ($bi === '') {
    echo json_encode(['error' => 'No bi provided']);
    exit;
}
$sql = "SELECT QC_ps, QC_ps2 FROM bom_ing WHERE bom_ing_fid = :bi LIMIT 1";
$stmt = $db->prepare($sql);
$stmt->bindParam(':bi', $bi, PDO::PARAM_STR);
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo json_encode($row ?: ['QC_ps' => '', 'QC_ps2' => '']);
