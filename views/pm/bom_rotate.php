<?php
/**
 * bom_rotate.php — 圖面查閱頁的「旋轉並存檔」端點（2026-08-25 使用者要求）
 *
 * 使用者拍板的三件事：
 *   ① 旋轉後**直接覆蓋原檔**（不另存新檔）——目的就是要讓所有人看到的都是轉正後的圖
 *   ② **全部圖片檔**都能轉（圖面／其他附件／訂單／報價附件），PDF 也支援（FPDI 匯入原頁，無損）
 *   ③ 權限＝**看得到就轉得動**（使用者原話：「旋轉不是真的修改，所以沒關係」），
 *      但後端仍要照鐵律8 再驗一次「這個人看不看得到這個來源的檔案」，不能只擋前端。
 *
 * 路徑解析與權限判定都走 src/common/bom_view_file_lib.php，旋轉本身走
 * src/common/image_rotate_lib.php（全站唯一實作，兩支都不在這裡重刻）。
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['userName'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '只接受 POST']);
    exit;
}

require_once __DIR__ . '/../../src/common/DBConnection.php';
require_once __DIR__ . '/../../src/common/bom_view_file_lib.php';
require_once __DIR__ . '/../../src/common/image_rotate_lib.php';

try {
    $pdo = (new DBConnection())->getPDO();
    $uid = (int)($_SESSION['id'] ?? 0);
    $url = (string)($_POST['url'] ?? '');
    $deg = (int)($_POST['deg'] ?? 90);

    $t = eg_bvf_parse_url($url);
    if (!$t) {
        echo json_encode(['success' => false, 'message' => '認不出這個檔案的來源，無法旋轉']);
        exit;
    }
    if (!eg_bvf_can_read($pdo, $uid, $t['src'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '沒有檢視這個檔案的權限']);
        exit;
    }
    $r = eg_bvf_resolve($pdo, $t);
    if (!$r) {
        echo json_encode(['success' => false, 'message' => '檔案不存在（可能已被移動或刪除）']);
        exit;
    }
    if (!in_array($r['ext'], eg_rotate_exts(), true)) {
        echo json_encode(['success' => false, 'message' => '這種格式不支援旋轉（只支援圖片檔與 PDF）']);
        exit;
    }
    $res = eg_rotate_file($r['fs'], $deg);
    $res['mtime'] = (int)@filemtime($r['fs']);   // 前端拿來破快取
    echo json_encode($res);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => '旋轉失敗：' . $e->getMessage()]);
}
