<?php
// 接收前端傳來的 Subscription（endpoint/p256dh/auth）與目前登入者 ID，存入 push_subscription。
// 以 endpoint 為唯一鍵：同一裝置重複允許或換帳號登入時，更新綁定的 user_id。
//
// 重要：本端點必定回傳「乾淨的 JSON」。關閉 HTML 錯誤輸出，避免 PHP Warning 的
// <br><b>...</b> 混入回應，導致前端 res.json() 解析失敗（Unexpected token '<'）。
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../common/_config.php'; // session_start + $db（用絕對路徑，避免 CWD 相對路徑失敗）

if (!isset($_SESSION['id'])) { echo json_encode(['ok' => false, 'msg' => '尚未登入']); exit(); }
$uid = (int)$_SESSION['id'];

// 前端以 JSON body 傳 subscription
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

$endpoint = trim($data['endpoint'] ?? '');
$p256dh   = trim($data['keys']['p256dh'] ?? ($data['p256dh'] ?? ''));
$auth     = trim($data['keys']['auth']   ?? ($data['auth'] ?? ''));

if ($endpoint === '' || $p256dh === '' || $auth === '') {
    echo json_encode(['ok' => false, 'msg' => '訂閱資料不完整']);
    exit();
}

$ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

// mode：'bind'＝使用者主動點「開啟通知」→ 這台裝置改綁到目前帳號（搶佔）
//       'refresh'＝頁面載入時自動同步 → 只更新金鑰，不更改綁定的帳號（不搶佔）
// 這樣同一台電腦/瀏覽器只有「最後一次主動開啟通知」的人擁有推播；別的帳號只是瀏覽不會偷走。
$mode = ($data['mode'] ?? $_POST['mode'] ?? 'bind');

try {
    // 重新訂閱視同復活：is_active 欄位存在時一併重設（失效標記見 push_send.php 規格 6-5）
    $hasActive = false;
    try { $hasActive = (bool)$db->query("SHOW COLUMNS FROM push_subscription LIKE 'is_active'")->fetchColumn(); } catch (Throwable $e) {}
    $reactivate = $hasActive ? ", is_active = 1, deactivated_at = NULL, fail_reason = NULL" : "";
    if ($mode === 'refresh') {
        // 不搶佔：存在則只更新金鑰/UA（保留原本 user_id）；不存在才以目前帳號新增
        $st = $db->prepare(
            "INSERT INTO push_subscription (user_id, endpoint, p256dh, auth, user_agent)
             VALUES (:uid, :ep, :p256, :auth, :ua)
             ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth), user_agent = VALUES(user_agent)$reactivate"
        );
    } else {
        // 主動綁定：以目前登入帳號搶佔此裝置
        $st = $db->prepare(
            "INSERT INTO push_subscription (user_id, endpoint, p256dh, auth, user_agent)
             VALUES (:uid, :ep, :p256, :auth, :ua)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh),
                                     auth = VALUES(auth), user_agent = VALUES(user_agent)$reactivate"
        );
    }
    $st->execute([':uid' => $uid, ':ep' => $endpoint, ':p256' => $p256dh, ':auth' => $auth, ':ua' => $ua]);

    // 主動綁定時整理訂閱：
    //  (1) 同帳號同一支裝置(user_id + user_agent) 只保留最新這一筆（清掉重新安裝/重綁的舊訂閱）
    //  (2) 同帳號最多 2 個裝置：只保留最新的 2 筆，其餘刪除
    if ($mode !== 'refresh') {
        $db->prepare("DELETE FROM push_subscription WHERE user_id = :u AND user_agent = :ua AND endpoint <> :ep")
           ->execute([':u' => $uid, ':ua' => $ua, ':ep' => $endpoint]);
        $db->prepare("DELETE FROM push_subscription WHERE user_id = :u AND id NOT IN (
                        SELECT id FROM (SELECT id FROM push_subscription WHERE user_id = :u2
                                        ORDER BY updated_at DESC, id DESC LIMIT 2) t)")
           ->execute([':u' => $uid, ':u2' => $uid]);
    }

    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'msg' => '資料庫錯誤']);
}
