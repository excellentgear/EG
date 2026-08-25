<?php
/**
 * bom_open_token.php — 發一張「一次性下載權杖」給用戶端的小畫家 VBScript 用
 *
 * 為什麼需要（2026-08-25 使用者回報「用小畫家開啟有問題」）：
 * 「小畫家」是把 `open-paint://…` 交給用戶端註冊的 VBScript，那支 VBScript 用
 * MSXML2.ServerXMLHTTP 去抓檔，**不會帶瀏覽器的登入 cookie**，所以只要檔案是走
 * 需要登入的附件 API（料號／訂單／報價附件），它一定拿到 403。
 * 這裡先在「有登入、有權限」的前提下換一張 3 分鐘、用過即失效的權杖，
 * VBScript 再拿權杖去打不需登入的 bom_open.php。
 *
 * 圖面分頁的 /nas/ 檔案本來就是 Apache 別名直接給檔、不需要權杖，前端不會呼叫這裡。
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['userName'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}

require_once __DIR__ . '/../../src/common/DBConnection.php';
require_once __DIR__ . '/../../src/common/bom_view_file_lib.php';

try {
    $pdo = (new DBConnection())->getPDO();
    $uid = (int)($_SESSION['id'] ?? 0);
    $url = (string)($_POST['url'] ?? $_GET['url'] ?? '');

    $t = eg_bvf_parse_url($url);
    if (!$t) {
        echo json_encode(['success' => false, 'message' => '認不出這個檔案的來源，無法用小畫家開啟']);
        exit;
    }
    // 權限：與圖面查閱頁的分頁權限同一把尺（唯一實作在 bom_view_file_lib）
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
    $tok = eg_bvf_token_issue($t, $uid);
    if ($tok === '') {
        echo json_encode(['success' => false, 'message' => '無法建立臨時下載連結（伺服器暫存區不可寫）']);
        exit;
    }
    echo json_encode([
        'success' => true,
        'token'   => $tok,
        'ext'     => $r['ext'],      // 前端要把副檔名接在網址結尾，VBScript 才知道要存成什麼檔
        'name'    => $r['name'],
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => '發生錯誤：' . $e->getMessage()]);
}
