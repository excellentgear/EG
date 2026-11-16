<?php
/**
 * 流程地圖 API
 * 讀取（list／get／pages）全體登入者；寫入（save／map_create／map_rename／map_delete）僅管理員，且驗 CSRF。
 */
session_start();
require_once __DIR__ . '/../common/api_guard.php';
header('Content-Type: application/json; charset=utf-8');
include_once __DIR__ . '/../common/DBConnection.php';
include_once __DIR__ . '/../common/flow_map_lib.php';

function fmOut(array $a) { echo json_encode(array_merge(['ok' => true], $a), JSON_UNESCAPED_UNICODE); exit; }
function fmErr(string $m, int $c = 400) { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m], JSON_UNESCAPED_UNICODE); exit; }

try { $db = (new DBConnection())->getPDO(); } catch (Throwable $e) { fmErr('DB連線失敗', 500); }
$uname = $_SESSION['userName'] ?? '';
if ($uname === '') fmErr('未登入', 401);
$st = $db->prepare("SELECT id FROM `user` WHERE user_uname=?");
$st->execute([$uname]);
$uid = (int)$st->fetchColumn();
if (!$uid) fmErr('未登入', 401);
fmap_ensure_schema($db);
$isAdmin = fmap_is_admin($db, $uid);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$WRITE = ['save', 'map_create', 'map_rename', 'map_delete'];
if (in_array($action, $WRITE, true)) {
    if (!$isAdmin) fmErr('只有管理員可以編輯流程地圖', 403);
    $tok = (string)($_SESSION['fmap_csrf'] ?? '');
    if ($tok === '' || !hash_equals($tok, (string)($_POST['csrf'] ?? ''))) fmErr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 403);
}

switch ($action) {
    case 'list':
        $rows = $db->query("SELECT map_id, map_name FROM flow_map ORDER BY sort_order, map_id")->fetchAll(PDO::FETCH_ASSOC);
        fmOut(['maps' => $rows, 'is_admin' => $isAdmin]);

    case 'get':
        $d = fmap_load($db, (int)($_GET['map_id'] ?? 0), $uid, $isAdmin);
        if (!$d) fmErr('找不到這張地圖', 404);
        fmOut($d + ['is_admin' => $isAdmin]);

    case 'pages':   // 編輯用：全部頁面清單（含群組名）
        if (!$isAdmin) fmErr('無權限', 403);
        $rows = $db->query("SELECT p.page_id, p.page_name, COALESCE(g.group_name,'') group_name
                            FROM system_module_pages p LEFT JOIN system_module_groups g ON g.group_id=p.group_id
                            ORDER BY g.sort_order, p.sort_order, p.page_id")->fetchAll(PDO::FETCH_ASSOC);
        fmOut(['pages' => $rows]);

    case 'map_create':
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 60) fmErr('地圖名稱請填 1~60 字');
        $mx = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM flow_map")->fetchColumn();
        $db->prepare("INSERT INTO flow_map (map_name, sort_order, created_at) VALUES (?,?,NOW())")->execute([$name, $mx + 1]);
        fmOut(['map_id' => (int)$db->lastInsertId()]);

    case 'map_rename':
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 60) fmErr('地圖名稱請填 1~60 字');
        $db->prepare("UPDATE flow_map SET map_name=? WHERE map_id=?")->execute([$name, (int)($_POST['map_id'] ?? 0)]);
        fmOut([]);

    case 'map_delete':
        $mid = (int)($_POST['map_id'] ?? 0);
        $db->beginTransaction();
        $db->prepare("DELETE FROM flow_map_edge WHERE map_id=?")->execute([$mid]);
        $db->prepare("DELETE FROM flow_map_node WHERE map_id=?")->execute([$mid]);
        $db->prepare("DELETE FROM flow_map WHERE map_id=?")->execute([$mid]);
        $db->commit();
        fmOut([]);

    case 'save':
        // 整張地圖覆蓋存檔：前端送 nodes（temp id 可為負數）與 edges（用同一組 id 指向節點）
        $mid = (int)($_POST['map_id'] ?? 0);
        $chk = $db->prepare("SELECT 1 FROM flow_map WHERE map_id=?"); $chk->execute([$mid]);
        if (!$chk->fetchColumn()) fmErr('找不到這張地圖', 404);
        $nodes = json_decode((string)($_POST['nodes'] ?? '[]'), true);
        $edges = json_decode((string)($_POST['edges'] ?? '[]'), true);
        if (!is_array($nodes) || !is_array($edges)) fmErr('資料格式錯誤');
        if (count($nodes) > 300 || count($edges) > 600) fmErr('節點或箭頭數量過多');
        $cw = max(600, min(6000, (int)($_POST['canvas_w'] ?? 1400)));
        $ch = max(400, min(6000, (int)($_POST['canvas_h'] ?? 700)));
        $okPages = array_column($db->query("SELECT page_id FROM system_module_pages")->fetchAll(PDO::FETCH_ASSOC), 'page_id');
        $okPages = array_flip(array_map('intval', $okPages));
        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM flow_map_edge WHERE map_id=?")->execute([$mid]);
            $db->prepare("DELETE FROM flow_map_node WHERE map_id=?")->execute([$mid]);
            $ins = $db->prepare("INSERT INTO flow_map_node (map_id,page_id,label,x,y,w,h,color) VALUES (?,?,?,?,?,?,?,?)");
            $idMap = [];
            foreach ($nodes as $n) {
                $pid = !empty($n['page_id']) ? (int)$n['page_id'] : null;
                if ($pid !== null && !isset($okPages[$pid])) throw new Exception('有節點指向不存在的頁面');
                $label = mb_substr(trim((string)($n['label'] ?? '')), 0, 60);
                if ($pid === null && $label === '') throw new Exception('文字方塊不可空白');
                $ins->execute([$mid, $pid, $label,
                    max(0, min(6000, (int)($n['x'] ?? 0))), max(0, min(6000, (int)($n['y'] ?? 0))),
                    max(80, min(400, (int)($n['w'] ?? 150))), max(30, min(200, (int)($n['h'] ?? 46))),
                    max(0, min(4, (int)($n['color'] ?? 0)))]);
                $idMap[(string)$n['id']] = (int)$db->lastInsertId();
            }
            $ie = $db->prepare("INSERT INTO flow_map_edge (map_id,from_node,to_node,label) VALUES (?,?,?,?)");
            $seen = [];
            foreach ($edges as $e) {
                $f = $idMap[(string)($e['from'] ?? '')] ?? null; $t = $idMap[(string)($e['to'] ?? '')] ?? null;
                if (!$f || !$t || $f === $t || isset($seen["$f-$t"])) continue;
                $seen["$f-$t"] = 1;
                $ie->execute([$mid, $f, $t, mb_substr(trim((string)($e['label'] ?? '')), 0, 30)]);
            }
            $db->prepare("UPDATE flow_map SET canvas_w=?, canvas_h=? WHERE map_id=?")->execute([$cw, $ch, $mid]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            fmErr($e->getMessage());
        }
        fmOut([]);

    default:
        fmErr('無效的操作');
}
