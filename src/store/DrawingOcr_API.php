<?php
/**
 * DrawingOcr_API.php — 圖面文字辨識（views/TD/drawing_text_recognize.php）唯一資料端點
 * 建立：2026-10-12
 *
 * 權限：drawing_ocr 模組角色 drawing_ocr_use，系統管理員自動涵蓋。
 * 本頁只讀不寫：ocr_image／dxf_parse 兩個動作都只是「辨識出文字」回傳給畫面，不寫入任何
 * 資料表；真的要建立料號時改連到 master_data_management.php（見該頁 ?new_part_no= 深連
 * 結），寫入仍走那一頁既有的 save_part 流程（鐵律4），所以本檔不需要 CSRF 寫入白名單。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/drawing_ocr_lib.php';
include_once $document_root . '/EGsystem/src/common/dxf_text_lib.php';

set_exception_handler(function ($e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '伺服器錯誤：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
});

function dorOut(array $a = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true], $a), JSON_UNESCAPED_UNICODE);
    exit;
}
function dorErr(string $msg, int $code = 400, array $extra = []) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$uid = (int)($_SESSION['id'] ?? 0);
if (!$uid) dorErr('未登入或連線已逾時，請重新登入', 401);
if (!drawing_ocr_can_use($db, $uid)) dorErr('您沒有「圖面文字辨識」的使用權限，請洽管理員指派', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    case 'ocr_image': {
        if (empty($_FILES['img']) || ($_FILES['img']['error'] ?? 1) !== UPLOAD_ERR_OK) {
            dorErr('沒有收到圖片檔，或上傳失敗');
        }
        $f = $_FILES['img'];
        if ($f['size'] > 15 * 1024 * 1024) dorErr('圖片超過 15MB 上限');
        // 用 getimagesize() 判型態，不用 mime_content_type()——那要靠 fileinfo 擴充套件，
        // Apache 這份 PHP 沒裝，getimagesize() 靠圖檔本身的 GD 既有支援就夠，不多一個相依。
        $sz = @getimagesize($f['tmp_name']);
        $okMime = ['image/png', 'image/jpeg', 'image/webp', 'image/bmp'];
        if (!$sz || !in_array($sz['mime'] ?? '', $okMime, true)) dorErr('不支援的圖片格式（僅支援 PNG/JPG/WEBP/BMP）');

        $r = eg_ocr_run_image($f['tmp_name']);
        if (!$r['ok']) dorErr($r['message'] ?? 'OCR 辨識失敗');
        dorOut(['width' => $r['width'], 'height' => $r['height'], 'lines' => $r['lines']]);
        break;
    }

    case 'dxf_parse': {
        if (empty($_FILES['dxf']) || ($_FILES['dxf']['error'] ?? 1) !== UPLOAD_ERR_OK) {
            dorErr('沒有收到 DXF 檔，或上傳失敗');
        }
        $f = $_FILES['dxf'];
        if ($f['size'] > 25 * 1024 * 1024) dorErr('DXF 檔超過 25MB 上限');
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if ($ext !== 'dxf') dorErr('僅支援 .dxf 檔（DWG 二進位格式請先在 CAD 軟體另存成 DXF）');

        $r = eg_dxf_parse_file($f['tmp_name']);
        if (!$r['ok']) dorErr($r['message'] ?? 'DXF 解析失敗');
        dorOut([
            'texts' => $r['texts'], 'lines' => $r['lines'], 'circles' => $r['circles'],
            'arcs' => $r['arcs'], 'polylines' => $r['polylines'], 'bbox' => $r['bbox'], 'counts' => $r['counts'],
        ]);
        break;
    }

    default:
        dorErr('不支援的操作');
}
