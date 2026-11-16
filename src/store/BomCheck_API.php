<?php
// BOM 檢核 API（標示問題／回報／異常解決／問題種類維護）。寫入動作一律驗 CSRF＋後端再驗權限。
include_once '../common/DBConnection.php';
include_once '../common/_config.php';
require_once '../common/bom_check_lib.php';
header('Content-Type: application/json; charset=utf-8');
function bchk_out($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
if (!isset($_SESSION['id'])) bchk_out(['success' => false, 'message' => '尚未登入']);
$uid = (int)$_SESSION['id'];
$action = (string)($_REQUEST['action'] ?? '');
try {
    if (!isset($db)) { $c = new DBConnection(); $db = $c->getPDO(); }
    eg_bomchk_ensure_schema($db);
    $WRITE = ['flag', 'reply', 'resolve', 'type_save', 'type_delete'];
    if (in_array($action, $WRITE, true)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') bchk_out(['success' => false, 'message' => '請求方式錯誤']);
        if (!eg_bomchk_csrf_ok($_POST['csrf'] ?? '')) bchk_out(['success' => false, 'message' => '連線憑證失效，請重新整理頁面後再試', 'code' => 'CSRF']);
    }
    $canCheck = eg_bomchk_can_check($db, $uid);
    $now = $db->query("SELECT NOW()")->fetchColumn();
    $me = eg_bomchk_uname($db, $uid);

    switch ($action) {
        case 'map':
            bchk_out(['success' => true, 'map' => (object)eg_bomchk_open_map($db), 'types' => eg_bomchk_types($db)]);
        case 'flag': { // 標示（或修改目前異常的種類／備註）
            if (!$canCheck) bchk_out(['success' => false, 'message' => '沒有 BOM 檢核權限']);
            $bom = trim((string)($_POST['bom'] ?? ''));
            $typeId = (int)($_POST['type_id'] ?? 0);
            $note = trim((string)($_POST['note'] ?? ''));
            if ($bom === '' || mb_strlen($bom) > 40) bchk_out(['success' => false, 'message' => 'BOM 編號不正確']);
            if (mb_strlen($note) > 500) bchk_out(['success' => false, 'message' => '備註最多 500 字']);
            $ex = $db->prepare("SELECT 1 FROM bom WHERE bom = ? LIMIT 1"); $ex->execute([$bom]);
            if (!$ex->fetchColumn()) bchk_out(['success' => false, 'message' => '找不到這張 BOM']);
            $t = $db->prepare("SELECT name FROM bom_check_type WHERE type_id = ? AND active = 1"); $t->execute([$typeId]);
            $tn = $t->fetchColumn();
            if ($tn === false) bchk_out(['success' => false, 'message' => '請選擇問題種類']);
            $cur = $db->prepare("SELECT check_id FROM bom_check WHERE bom = ? AND status='open' LIMIT 1"); $cur->execute([$bom]);
            $cid = (int)$cur->fetchColumn();
            if ($cid) {
                $db->prepare("UPDATE bom_check SET type_id=?, type_name=?, note=? WHERE check_id=?")->execute([$typeId, $tn, $note, $cid]);
            } else {
                $db->prepare("INSERT INTO bom_check (bom,type_id,type_name,note,status,created_by,created_by_name,created_at) VALUES (?,?,?,?, 'open',?,?,?)")
                   ->execute([$bom, $typeId, $tn, $note, $uid, $me, $now]);
            }
            bchk_out(['success' => true, 'map' => (object)eg_bomchk_open_map($db)]);
        }
        case 'reply': {
            if (!eg_bomchk_can_report($db, $uid)) bchk_out(['success' => false, 'message' => '沒有回報權限']);
            $cid = (int)($_POST['check_id'] ?? 0);
            $content = trim((string)($_POST['content'] ?? ''));
            $done = ((string)($_POST['is_done'] ?? '') === '1') ? 1 : 0;
            if ($content === '') bchk_out(['success' => false, 'message' => '請輸入說明']);
            if (mb_strlen($content) > 1000) bchk_out(['success' => false, 'message' => '說明最多 1000 字']);
            $s = $db->prepare("SELECT status FROM bom_check WHERE check_id = ?"); $s->execute([$cid]);
            $st = $s->fetchColumn();
            if ($st === false) bchk_out(['success' => false, 'message' => '找不到這筆檢核']);
            if ($st !== 'open') bchk_out(['success' => false, 'message' => '這筆異常已被解決，請重新整理', 'code' => 'STALE', 'map' => (object)eg_bomchk_open_map($db)]);
            $db->prepare("INSERT INTO bom_check_reply (check_id,content,is_done,by_id,by_name,created_at) VALUES (?,?,?,?,?,?)")
               ->execute([$cid, $content, $done, $uid, $me, $now]);
            bchk_out(['success' => true, 'map' => (object)eg_bomchk_open_map($db)]);
        }
        case 'resolve': {
            if (!$canCheck) bchk_out(['success' => false, 'message' => '沒有 BOM 檢核權限']);
            $cid = (int)($_POST['check_id'] ?? 0);
            $u = $db->prepare("UPDATE bom_check SET status='resolved', resolved_by=?, resolved_by_name=?, resolved_at=? WHERE check_id=? AND status='open'");
            $u->execute([$uid, $me, $now, $cid]);
            if (!$u->rowCount()) bchk_out(['success' => false, 'message' => '這筆異常已被解決或不存在，請重新整理', 'code' => 'STALE', 'map' => (object)eg_bomchk_open_map($db)]);
            bchk_out(['success' => true, 'map' => (object)eg_bomchk_open_map($db)]);
        }
        case 'types':
            if (!$canCheck) bchk_out(['success' => false, 'message' => '沒有 BOM 檢核權限']);
            bchk_out(['success' => true, 'types' => eg_bomchk_types($db, false)]);
        case 'type_save': {
            if (!$canCheck) bchk_out(['success' => false, 'message' => '沒有 BOM 檢核權限']);
            $tid = (int)($_POST['type_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $active = ((string)($_POST['active'] ?? '1') === '0') ? 0 : 1;
            if ($name === '' || mb_strlen($name) > 40) bchk_out(['success' => false, 'message' => '種類名稱請填 1~40 字']);
            $d = $db->prepare("SELECT type_id FROM bom_check_type WHERE name = ? AND type_id <> ?"); $d->execute([$name, $tid]);
            if ($d->fetchColumn()) bchk_out(['success' => false, 'message' => '已有相同名稱的種類']);
            if ($tid) {
                $db->prepare("UPDATE bom_check_type SET name=?, active=? WHERE type_id=?")->execute([$name, $active, $tid]);
            } else {
                $mx = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM bom_check_type")->fetchColumn();
                $db->prepare("INSERT INTO bom_check_type (name, sort_order, active) VALUES (?,?,1)")->execute([$name, $mx + 10]);
            }
            bchk_out(['success' => true, 'types' => eg_bomchk_types($db, false)]);
        }
        case 'type_delete': { // 已被使用過的只能停用，避免歷史紀錄的種類對不上
            if (!$canCheck) bchk_out(['success' => false, 'message' => '沒有 BOM 檢核權限']);
            $tid = (int)($_POST['type_id'] ?? 0);
            $u = $db->prepare("SELECT COUNT(*) FROM bom_check WHERE type_id = ?"); $u->execute([$tid]);
            if ((int)$u->fetchColumn() > 0) bchk_out(['success' => false, 'message' => '這個種類已有檢核紀錄使用，請改為停用（取消勾選「啟用」）']);
            $db->prepare("DELETE FROM bom_check_type WHERE type_id = ?")->execute([$tid]);
            bchk_out(['success' => true, 'types' => eg_bomchk_types($db, false)]);
        }
        default:
            bchk_out(['success' => false, 'message' => '無效的操作']);
    }
} catch (Throwable $e) {
    error_log('BomCheck_API: ' . $e->getMessage());
    bchk_out(['success' => false, 'message' => '系統錯誤']);
}
