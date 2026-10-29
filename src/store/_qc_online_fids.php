<?php
// 有「線上檢驗」資料（views/QC/inspection_entry_v2.php 的 qc_check_form）的製程 bom_ing_fid 清單。
// BOM總覽用它區分「有線上檢驗紀錄」與「簡易檢驗／QC補報檢驗」（qc_check）。唯讀、體積很小（目前數十筆）。
if (ob_get_level()) ob_end_clean();
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!isset($_SESSION['userName'])) { http_response_code(401); echo json_encode(['success' => false]); exit; }
include_once '../common/DBConnection.php';
try {
    $db = (new DBConnection())->getPDO();
    $ids = $db->query("SELECT DISTINCT bom_ing_fid FROM qc_check_form WHERE bom_ing_fid IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['success' => true, 'fids' => array_map('intval', $ids)]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'fids' => []]);
}
