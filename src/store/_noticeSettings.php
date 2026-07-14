<?php
// 公告/通知 設定（目前：附件基礎儲存路徑）。需管理員(all)。
header('Content-Type: application/json; charset=utf-8');
include("../../src/common/_config.php"); // session_start + $db
require_once __DIR__ . '/../common/rbac.php';
require_once __DIR__ . '/../common/notice_files.php';

if (!isset($_SESSION['id'])) { echo json_encode(['ok' => false, 'msg' => '尚未登入']); exit(); }
$uid = (int)$_SESSION['id'];
$isAdmin = rbac_has(rbac_user_features($db, $uid), 'all');

$action = $_POST['action'] ?? $_GET['action'] ?? 'get';

try {
    if ($action === 'save') {
        if (!$isAdmin) { echo json_encode(['ok' => false, 'msg' => '無管理員權限']); exit(); }
        $base = trim($_POST['base'] ?? '');
        // 儲存（允許空＝改用預設 uploads/notice）
        $st = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by, updated_at)
                            VALUES ('notice_attach_base', ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by_id = VALUES(updated_by_id), updated_by = VALUES(updated_by), updated_at = NOW()");
        $st->execute([$base, $uid, ($_SESSION['user_cname'] ?? '')]);

        // 測試寫入能力（實際建立測試資料夾/檔）
        $test = ['ok' => false, 'msg' => ''];
        $dir = eg_notice_base($db) . DIRECTORY_SEPARATOR . '__wtest__';
        @mkdir($dir, 0775, true);
        $f = $dir . DIRECTORY_SEPARATOR . 'w.txt';
        if (@file_put_contents($f, 'ok') !== false) { $test['ok'] = true; @unlink($f); @rmdir($dir); }
        else { $test['msg'] = '路徑無法寫入（請確認 Apache 帳號對此路徑有權限；網路磁碟請用 UNC 路徑 \\\\主機\\分享\\…）'; }

        echo json_encode(['ok' => true, 'base' => eg_notice_base($db), 'writable' => $test['ok'], 'writable_msg' => $test['msg']], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // get
    $base = eg_notice_base($db);
    echo json_encode(['ok' => true, 'base' => $base, 'is_admin' => $isAdmin], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'msg' => '資料庫錯誤']);
}
