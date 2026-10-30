<?php
// BOM 相關紀錄時間軸（純讀取，登入即可看；BOM總覽與已完工BOM查詢共用）
include_once '../common/DBConnection.php';
include_once '../common/_config.php';
require_once '../common/bom_timeline_lib.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['id'])) { echo json_encode(['success' => false, 'message' => '未登入']); exit; }
session_write_close();
$bom = trim((string)($_REQUEST['bom'] ?? ''));
if ($bom === '') { echo json_encode(['success' => false, 'message' => '缺少 BOM']); exit; }
try {
    if (!isset($db)) { $c = new DBConnection(); $db = $c->getPDO(); }
    $co = '';
    try { $co = (string)$db->query("SELECT customer_full FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetchColumn(); } catch (Throwable $e2) {}
    echo json_encode(['success' => true, 'bom' => $bom, 'company' => $co, 'events' => eg_bom_timeline($db, $bom)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
