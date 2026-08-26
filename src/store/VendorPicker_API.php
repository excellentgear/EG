<?php
/**
 * 廠商模糊搜尋 API —— 全站共用（2026-08-26 建立）
 *
 * 解決的問題：多個表單要填「廠商」，但廠商主檔 maker_list 有 900 筆左右，純下拉沒有人找得到
 * （UI 規則：超過約 10 筆的清單一律要能打字篩選）。且現場記得的可能是廠商代號(maker_id_no)、
 * 也可能是簡稱(maker_id)或全名(maker_id_all)，三個欄位都要能比對。
 *
 * action=search：GET/POST q=關鍵字（同時比對 maker_id_no / maker_id / maker_id_all，多關鍵字空白分隔＝每個都要命中）
 *   回傳最多 30 筆 {maker_id_no, maker_id, maker_id_all, m_category, is_disabled}
 *   預設不列已停用(status='X')的廠商；補歷史紀錄時可帶 include_disabled=1 一併列出（會標示已停用）。
 * action=get_one：GET/POST id=maker_id_no，回傳單筆（供編輯既有紀錄時把代號還原成名稱）
 *
 * 前端唯一實作：resource/js/eg_vendor_picker.js（禁止各頁自刻廠商搜尋 UI）
 */
header('Content-Type: application/json; charset=utf-8');

$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門（離職/留停者一律 403）
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';

if (!isset($_SESSION['userName']) && !isset($_SESSION['id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未登入'], JSON_UNESCAPED_UNICODE); exit;
}

$db = (new DBConnection())->getPDO();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function vpk_out($arr) { echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

const VPK_COLS = "maker_id_no, COALESCE(maker_id,'') AS maker_id, COALESCE(maker_id_all,'') AS maker_id_all,
                  COALESCE(m_category,'') AS m_category, CASE WHEN status='X' THEN 1 ELSE 0 END AS is_disabled";

switch ($action) {

case 'search': {
    $q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
    $inclDisabled = !empty($_GET['include_disabled'] ?? $_POST['include_disabled'] ?? '');
    if ($q === '') vpk_out(['success' => true, 'rows' => []]);
    // 多關鍵字：每個關鍵字都要命中（可分散在代號/簡稱/全名三個欄位），比照全表搜尋鐵則
    $words = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
    $where = []; $params = [];
    foreach (array_slice($words, 0, 5) as $w) {
        $where[] = "(maker_id_no LIKE ? OR maker_id LIKE ? OR maker_id_all LIKE ?)";
        $like = '%' . $w . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if (!$inclDisabled) $where[] = "(status IS NULL OR status <> 'X')";
    $sql = "SELECT " . VPK_COLS . " FROM maker_list WHERE " . implode(' AND ', $where)
         . " ORDER BY (status='X'), maker_id_no LIMIT 30";
    $st = $db->prepare($sql);
    $st->execute($params);
    vpk_out(['success' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

case 'get_one': {
    $id = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
    if ($id === '') vpk_out(['success' => false, 'message' => '缺少廠商代號']);
    $st = $db->prepare("SELECT " . VPK_COLS . " FROM maker_list WHERE maker_id_no=? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    vpk_out(['success' => (bool)$row, 'row' => $row ?: null]);
}

default:
    vpk_out(['success' => false, 'message' => '未知動作']);
}
