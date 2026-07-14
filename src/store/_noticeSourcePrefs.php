<?php
// 公告列表「來源顯示設定」API（全站共用設定）
// - list：回傳所有來源與目前隱藏清單
// - save：儲存隱藏清單（system_settings.notice_hidden_sources，JSON 陣列）
// 權限：notice_edit / notice_delete / 管理者(all)。此設定只影響列表顯示，推播通知不受影響。
header('Content-Type: application/json; charset=utf-8');
include("../../src/common/_config.php"); // session_start + $db
require_once __DIR__ . '/../common/rbac.php';

if (!isset($_SESSION['id'])) { echo json_encode(['ok' => false, 'msg' => '尚未登入']); exit(); }
$features = rbac_user_features($db, (int)$_SESSION['id']);
if (!(rbac_has($features, 'all') || rbac_has($features, 'notice_edit') || rbac_has($features, 'notice_delete'))) {
    echo json_encode(['ok' => false, 'msg' => '無權限']); exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

function nsp_hidden(PDO $db): array {
    try {
        $v = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='notice_hidden_sources' LIMIT 1")->fetchColumn();
        return array_values(array_filter((array)json_decode((string)$v, true), 'is_string'));
    } catch (Throwable $e) { return []; }
}

try {
    if ($action === 'save') {
        $hidden = array_values(array_filter((array)json_decode($_POST['hidden'] ?? '[]', true), 'is_string'));
        $hidden = array_slice(array_map(function ($s) { return mb_substr(trim($s), 0, 50, 'UTF-8'); }, $hidden), 0, 100);
        $json = json_encode($hidden, JSON_UNESCAPED_UNICODE);
        $st = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('notice_hidden_sources', ?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $st->execute([$json]);
        echo json_encode(['ok' => true]);
        exit();
    }

    // list
    $hidden = nsp_hidden($db);
    $sources = [];
    foreach ($db->query("SELECT DISTINCT source FROM live_event WHERE source IS NOT NULL AND source <> '' ORDER BY source")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $sources[] = ['name' => $s, 'hidden' => in_array($s, $hidden, true)];
    }
    echo json_encode(['ok' => true, 'sources' => $sources], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[noticeSourcePrefs] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => '系統錯誤']);
}
