<?php
// 已訂閱推播的裝置清單（供公告管理頁檢視）：顯示人員、裝置類型、訂閱時間、最後推播。
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../common/_config.php';   // session_start + $db
require_once __DIR__ . '/../common/rbac.php';

if (!isset($_SESSION['id'])) { echo json_encode(['ok' => false, 'msg' => '尚未登入']); exit(); }
$features = rbac_user_features($db, (int)$_SESSION['id']);
if (!(rbac_has($features, 'notice_edit') || rbac_has($features, 'notice_delete') || rbac_has($features, 'all'))) {
    echo json_encode(['ok' => false, 'msg' => '無權限']); exit();
}

// 由 User-Agent 判斷裝置/系統類型
function eg_device_type($ua) {
    $ua = (string)$ua;
    if (preg_match('/iPhone/i', $ua))                 return ['iPhone', 'fa-apple'];
    if (preg_match('/iPad/i', $ua))                   return ['iPad', 'fa-apple'];
    if (preg_match('/Android/i', $ua))                return ['Android 手機', 'fa-android'];
    if (preg_match('/Windows/i', $ua))                return ['Windows 電腦', 'fa-windows'];
    if (preg_match('/Macintosh|Mac OS/i', $ua))       return ['Mac 電腦', 'fa-apple'];
    if (preg_match('/Linux/i', $ua))                  return ['Linux', 'fa-linux'];
    return ['其他裝置', 'fa-desktop'];
}
// 瀏覽器（輔助）
function eg_browser($ua) {
    $ua = (string)$ua;
    if (preg_match('/Edg/i', $ua))     return 'Edge';
    if (preg_match('/CriOS|Chrome/i', $ua)) return 'Chrome';
    if (preg_match('/Firefox/i', $ua)) return 'Firefox';
    if (preg_match('/Safari/i', $ua))  return 'Safari';
    return '';
}

try {
    // 同帳號僅顯示最新 2 筆（訂閱上限為 2 個裝置，更舊的訂閱屬清理前殘留資料，不顯示）
    $rows = $db->query(
        "SELECT s.user_id, s.user_agent, s.created_at, s.last_used_at, u.user_cname
         FROM (
             SELECT s1.*, ROW_NUMBER() OVER (PARTITION BY s1.user_id ORDER BY s1.id DESC) AS rn
             FROM push_subscription s1
         ) s
         LEFT JOIN `user` u ON u.id = s.user_id
         WHERE s.rn <= 2
         ORDER BY u.user_cname ASC, s.id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $data = [];
    foreach ($rows as $r) {
        [$dev, $ico] = eg_device_type($r['user_agent']);
        $br = eg_browser($r['user_agent']);
        $data[] = [
            'name'         => $r['user_cname'] ?: ('#' . $r['user_id']),
            'device'       => $dev . ($br ? '（' . $br . '）' : ''),
            'icon'         => $ico,
            'created_at'   => $r['created_at'],
            'last_used_at' => $r['last_used_at'],
        ];
    }

    // 統計：訂閱裝置數、涵蓋人數
    $users = count(array_unique(array_column($rows, 'user_id')));
    echo json_encode(['ok' => true, 'total' => count($data), 'users' => $users, 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'msg' => '資料庫錯誤']);
}
