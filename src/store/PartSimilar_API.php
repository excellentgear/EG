<?php
// PartSimilar_API.php —— 類似料號提示用 API（2026-10-16）。唯一實作在 src/common/part_similar_lib.php。
//
// 讀取類（check／order_check／compare／quote_detail／order_detail）：登入且在職即可，全部唯讀。
// 寫入類（settings_save）：僅全站系統管理員，並驗 CSRF。
// 刻意：任何讀取動作出錯都回「沒有類似料號」而不是 500，這個功能是輔助提示，不可以影響呼叫它的頁面。
session_start();
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['id'])) { http_response_code(403); echo json_encode(['success' => false, 'message' => '未登入']); exit; }

require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/gear_spec_lib.php';       // eg_gear_module_text（模數怎麼印的唯一實作）
require_once __DIR__ . '/../common/part_similar_lib.php';

$pdo = (new DBConnection())->getPDO();
$uid = (int)$_SESSION['id'];
if (empty($_SESSION['ps_csrf'])) $_SESSION['ps_csrf'] = bin2hex(random_bytes(16));

function psReply(array $d) { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

function psIsAdmin(PDO $pdo, int $uid): bool {
    if ($uid === 1) return true;
    try {
        require_once __DIR__ . '/../common/role_features_helper.php';
        $f = rf_load_user_features_all($pdo, $uid);
        return !empty($f) && rf_has_feature($f, 'all');
    } catch (Throwable $e) { return false; }
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
switch ($action) {

    // 找類似料號。ctx＝quote(報價單)／order(訂單)／design(批圖)／master(主檔)：
    //   quote 帶回「對方最近三筆報價」，order 帶回「最近三筆訂單」，其餘不帶歷史。
    case 'check':
    case 'order_check': {
        $dId = (int)($_POST['d_id'] ?? $_GET['d_id'] ?? 0);
        if ($action === 'order_check') {   // 批圖那邊只知道訂單，d_id 由後端查（不信前端）
            $oid = (int)($_POST['order_id'] ?? 0);
            $st = $pdo->prepare("SELECT d_id_ID FROM order_track WHERE Order_id=?");
            $st->execute([$oid]);
            $dId = (int)$st->fetchColumn();
        }
        $ctx = (string)($_POST['ctx'] ?? $_GET['ctx'] ?? 'master');
        if ($dId <= 0) psReply(['success' => true, 'items' => [], 'total' => 0]);
        $r = ps_find($pdo, $dId);
        if ($r['items'] && ($ctx === 'quote' || $ctx === 'order' || $ctx === 'bom')) {
            foreach ($r['items'] as &$it) {
                $it['history'] = ($ctx === 'quote') ? ps_recent_quotes($pdo, (int)$it['d_id'], 3)
                               : (($ctx === 'bom') ? ps_recent_boms($pdo, (int)$it['d_id'], 3) : ps_recent_orders($pdo, (int)$it['d_id'], 3));
            }
            unset($it);
        }
        psReply(['success' => true, 'ctx' => $ctx] + $r);
    }

    case 'find_part': {
        psReply(['success' => true, 'rows' => ps_search_parts($pdo, (string)($_POST['kw'] ?? ''))]);
    }

    case 'compare': {
        $a = (int)($_POST['a'] ?? $_GET['a'] ?? 0);
        $b = (int)($_POST['b'] ?? $_GET['b'] ?? 0);
        if ($a <= 0 || $b <= 0) psReply(['success' => false, 'message' => '參數錯誤']);
        $r = ps_compare($pdo, $a, $b);
        psReply(['success' => !empty($r['ok'])] + $r);
    }

    case 'quote_detail': {
        $d = ps_quote_detail($pdo, (int)($_POST['item_id'] ?? $_GET['item_id'] ?? 0));
        psReply(['success' => (bool)$d, 'data' => $d]);
    }

    case 'order_detail': {
        $d = ps_order_detail($pdo, (int)($_POST['order_id'] ?? $_GET['order_id'] ?? 0));
        psReply(['success' => (bool)$d, 'data' => $d]);
    }

    // 設定：讀取任何人都可以（前端要知道有沒有開啟）；csrf 只在管理員身分才給
    case 'settings_get': {
        $s = ps_settings($pdo);
        $isAdm = psIsAdmin($pdo, $uid);
        psReply(['success' => true, 'enabled' => $s['enabled'], 'labels' => $s['labels'], 'is_admin' => $isAdm,
                 'choices' => $isAdm ? ps_label_choices($pdo) : [],
                 'csrf' => $isAdm ? (string)$_SESSION['ps_csrf'] : '']);
    }

    case 'settings_save': {
        if (!psIsAdmin($pdo, $uid)) { http_response_code(403); psReply(['success' => false, 'message' => '只有系統管理員可以設定']); }
        $tok = $_POST['csrf'] ?? '';
        if (!is_string($tok) || $tok === '' || !hash_equals((string)$_SESSION['ps_csrf'], $tok)) {
            http_response_code(400); psReply(['success' => false, 'message' => '連線憑證失效，請重新整理頁面後再試 (CSRF)']);
        }
        $labels = json_decode((string)($_POST['labels'] ?? '[]'), true);
        if (!is_array($labels)) $labels = [];
        $r = ps_save_settings($pdo, (int)($_POST['enabled'] ?? 1) ? 1 : 0, $labels);
        psReply(['success' => !empty($r['ok']), 'message' => $r['message'] ?? '已儲存', 'labels' => $r['labels'] ?? []]);
    }

    default:
        psReply(['success' => false, 'message' => '無效的操作']);
}
} catch (Throwable $e) {
    error_log('PartSimilar_API: ' . $e->getMessage());
    psReply(['success' => false, 'message' => '處理失敗', 'items' => [], 'total' => 0]);
}
