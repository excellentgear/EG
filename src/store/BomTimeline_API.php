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
    echo json_encode(['success' => true, 'bom' => $bom, 'events' => eg_bom_timeline($db, $bom)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
