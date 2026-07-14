<?php
// 移除訂閱：使用者關閉通知，或瀏覽器 subscription 變更時呼叫。以 endpoint 刪除。
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../common/_config.php'; // session_start + $db

if (!isset($_SESSION['id'])) { echo json_encode(['ok' => false, 'msg' => '尚未登入']); exit(); }

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;
$endpoint = trim($data['endpoint'] ?? '');

if ($endpoint === '') { echo json_encode(['ok' => false, 'msg' => '缺少 endpoint']); exit(); }

try {
    // 僅能解除「自己帳號」的訂閱：防止取得他人 endpoint 後代為解除
    $db->prepare("DELETE FROM push_subscription WHERE endpoint = ? AND user_id = ?")
       ->execute([$endpoint, (int)$_SESSION['id']]);
    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'msg' => '資料庫錯誤']);
}
