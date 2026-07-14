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

if (isset($_FILES['file']['name'])) {
    $file = $_FILES['file']['tmp_name'];
    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet();
    $data = $sheet->toArray();

    // 插入數據
    $stmt = $db->prepare("INSERT INTO reply (reply_id, BOM, bom_ing_id, ps, Client_Name, sqty, oready_sqty, ProcessNo, MakerId, ok_sqty, ng_sqty, ng_id, ng_sqty2, ng_id2, ng_sqty3, ng_id3, completed, Created_By, Created_At, Modified_By, Modified_At) 
    VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($data as $row) {
        $stmt->execute($row);
    }
    
    echo "資料已成功插入";
} else {
    echo "請上傳一個Excel文件";
}


header("location:../../views/reply/reply_other.php?pti=".$_GET['pti']."&ri=".$_GET['ri']."&bi=".$_GET['bi']."&BOM=".$_GET['BOM']."&d=".$_GET['d']."&pna=".$_GET['pna']."&ProcessNo=".$_GET['pn']."&MakerId=".$_GET['mi']."&sqty=".$_GET['s']."&C=".$_GET['C']."&rd=".$_GET['rd']."");
