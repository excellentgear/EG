<?php
/**
 * 料號模糊搜尋 API —— 全站共用（2026-08-11 建立）
 *
 * 解決的問題：多個新頁面（型態識別文件管制表／產品開發評估表／PFMEA）都需要「打部分字元篩選料號、
 * 選定後自動帶出客戶」的欄位。料號主檔 d_setting 筆數大（3000+），不能比照 eg_asdoc_picker.js
 * 整批載入前端篩選，改用後端 LIKE 搜尋 + 前端 resource/js/eg_part_picker.js 呼叫。
 *
 * action=search：GET/POST q=關鍵字（比對 D_Setting_Id / Drawing_No），回傳最多 30 筆
 *   {d_id, part_no, drawing_no, customer_id, customer_name, is_assembly}
 */
header('Content-Type: application/json; charset=utf-8');

$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';

if (!isset($_SESSION['userName'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未登入']); exit;
}

$db = (new DBConnection())->getPDO();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function jout($arr) { echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

switch ($action) {

case 'search':
    $q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
    if ($q === '' || mb_strlen($q) < 1) jout(['success' => true, 'rows' => []]);
    $like = '%' . $q . '%';
    $st = $db->prepare(
        "SELECT ds.d_id, ds.D_Setting_Id AS part_no, COALESCE(ds.Drawing_No,'') AS drawing_no,
                COALESCE(ds.Customer_Id,'') AS customer_id, COALESCE(cl.customer,'') AS customer_name,
                COALESCE(ds.Is_Assembly,0) AS is_assembly
         FROM d_setting ds
         LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
         WHERE ds.D_Setting_Id LIKE ? OR ds.Drawing_No LIKE ?
         ORDER BY ds.D_Setting_Id LIMIT 30");
    $st->execute([$like, $like]);
    jout(['success' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);

case 'get_one':
    $dId = (int)($_GET['d_id'] ?? $_POST['d_id'] ?? 0);
    if (!$dId) jout(['success' => false, 'message' => '缺少 d_id']);
    $st = $db->prepare(
        "SELECT ds.d_id, ds.D_Setting_Id AS part_no, COALESCE(ds.Drawing_No,'') AS drawing_no,
                COALESCE(ds.Customer_Id,'') AS customer_id, COALESCE(cl.customer,'') AS customer_name,
                COALESCE(ds.Is_Assembly,0) AS is_assembly
         FROM d_setting ds
         LEFT JOIN customer_list cl ON cl.customer_id = ds.Customer_Id
         WHERE ds.d_id = ? LIMIT 1");
    $st->execute([$dId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    jout(['success' => (bool)$row, 'row' => $row ?: null]);

default:
    jout(['success' => false, 'message' => '未知動作']);
}
