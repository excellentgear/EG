<?php
// OrderAsTag_API.php — 訂單追蹤「稽核製程標籤」專用 API（2026-10-02 使用者交辦）
// 唯一實作在 src/common/order_as_tag_lib.php，本檔只負責權限守門與參數收發。
//
// 兩種門檻（鐵律8：畫面擋過一次，這裡用同一套規則再擋一次）：
//   ① 讀選項（options／order_log）＝看得到訂單追蹤頁的人就讀得到（ot_view）
//   ② 設定標籤定義／必選開關／批次補設定＝需要 ot_as_tag_setting（管理員 all 亦可）
// 註：訂單自己的標籤是跟著「新增/編輯訂單」存檔一起寫的（src/store/_NewOrder_Track.php），
//     不走這支 API——那樣才跟訂單內容同一個交易，不會出現「訂單存進去了、標籤沒跟上」。
session_start();
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門（離職/留停者一律 403）
if (!isset($_SESSION['userName'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/order_track_perm_lib.php';   // ot_has_feature()／ot_is_admin()
require_once __DIR__ . '/../common/order_as_tag_lib.php';

$pdo = (new DBConnection())->getPDO();
$uid = (int)($_SESSION['id'] ?? 0);
$uname = ot_astag_uname($pdo, $uid);
if ($uname === '') $uname = (string)($_SESSION['userName'] ?? '');

ot_astag_ensure_schema($pdo);

function oatReply(array $d) { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function oatDeny(string $msg = '沒有權限執行這項操作') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
/** 設定／批次補設定門檻：ot_as_tag_setting（或系統管理員）。
 *  刻意**不 fail-open**：ot_has_feature() 對「完全沒被指派角色」的人會回 true（過渡期相容），
 *  那對這種會一次改幾千張訂單的動作不適用——畫面上那些人根本看不到入口，
 *  後端放行等於直接打 API 就能整批覆寫。 */
function oatCanSetting(PDO $pdo, int $uid): bool {
    if ($uid <= 0) return false;
    try {
        require_once __DIR__ . '/../common/role_features_helper.php';
        $f = rf_load_user_features_all($pdo, $uid);
        if (empty($f)) return false;
        return rf_has_feature($f, 'all') || rf_has_feature($f, 'ot_as_tag_setting');
    } catch (Throwable $e) { return false; }
}
/** 讀取門檻：看得到訂單追蹤頁的人（ot_view），查詢失敗一律放行避免鎖死（與頁面 ot_hasF 同規則） */
function oatCanView(PDO $pdo, int $uid): bool {
    try { return ot_has_feature($pdo, $uid, 'ot_view'); } catch (Throwable $e) { return true; }
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ── 某張訂單（或某個客戶）可以選哪些標籤 ────────────────────────────
    // 新增/編輯訂單跳窗、客戶換掉時都呼叫這支；客戶不是本公司時廠內治具不會出現在清單裡。
    case 'options': {
        if (!oatCanView($pdo, $uid)) oatDeny();
        $cid = trim((string)($_POST['client_id'] ?? $_GET['client_id'] ?? ''));
        $own = ot_astag_is_own_company($pdo, $cid);
        oatReply([
            'success'      => true,
            'options'      => ot_astag_options($pdo, $own),
            'is_own'       => $own ? 1 : 0,
            'own_company'  => ot_astag_own_company_id($pdo),
            'require_save' => ot_astag_require_save($pdo) ? 1 : 0,
            'can_setting'  => oatCanSetting($pdo, $uid) ? 1 : 0,
        ]);
    }

    // ── 一張訂單的標籤異動歷程（唯讀，看得到本頁的人都能看）──────────────
    case 'order_log': {
        if (!oatCanView($pdo, $uid)) oatDeny();
        $oid = (int)($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
        if ($oid <= 0) oatReply(['success' => false, 'message' => '缺少訂單編號']);
        $cur = ot_astag_for_order($pdo, $oid);
        $rows = [];
        try {
            $st = $pdo->prepare("SELECT old_label, new_label, source, note, created_by_name, created_at
                                 FROM ot_as_tag_order_log WHERE order_id=? ORDER BY id DESC LIMIT 100");
            $st->execute([$oid]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}
        oatReply(['success' => true, 'current' => $cur, 'rows' => $rows]);
    }

    // ── 設定頁：目前的標籤定義 ＋ 製程大類/小類樹 ＋ 使用筆數 ＋ 必選開關 ──
    case 'defs': {
        if (!oatCanSetting($pdo, $uid)) oatDeny();
        oatReply([
            'success'      => true,
            'defs'         => ot_astag_defs($pdo, false),   // 連停用的一起列，設定頁才改得回來
            // 篩選列的標籤下拉是頁面載入當下由 PHP 排好的，管理員剛改完設定它會是舊的；
            // 這裡把「目前全部可選標籤」一併回傳，前端就地重建這個下拉（不必重新整理頁面）
            'options_all'  => ot_astag_options($pdo, true),
            'tree'         => ot_astag_process_tree($pdo),
            'usage'        => ot_astag_usage($pdo),
            'require_save' => ot_astag_require_save($pdo) ? 1 : 0,
            'summary'      => ot_astag_backfill_summary($pdo),
            'own_company'  => ot_astag_own_company_id($pdo),
        ]);
    }

    // ── 設定頁：儲存標籤定義（整份送上來，後端自己 diff）────────────────
    case 'defs_save': {
        if (!oatCanSetting($pdo, $uid)) oatDeny();
        $rows = json_decode((string)($_POST['rows'] ?? '[]'), true);
        if (!is_array($rows)) oatReply(['success' => false, 'message' => '資料格式有誤（rows 不是陣列）']);
        if (count($rows) > 100) oatReply(['success' => false, 'message' => '稽核製程標籤最多 100 筆']);
        $r = ot_astag_save_defs($pdo, $rows, $uid, $uname);
        if (!$r['ok']) oatReply(['success' => false, 'message' => implode("\n", $r['errors']), 'errors' => $r['errors']]);
        oatReply(['success' => true, 'message' => "已儲存（新增 {$r['added']}、修改 {$r['updated']}、刪除 {$r['deleted']}）",
                  'added' => $r['added'], 'updated' => $r['updated'], 'deleted' => $r['deleted']]);
    }

    // ── 設定頁：存檔必選開關 ──────────────────────────────────────────
    case 'require_save': {
        if (!oatCanSetting($pdo, $uid)) oatDeny();
        $on = (!empty($_POST['on']) && $_POST['on'] !== '0') ? 1 : 0;
        if (!ot_astag_param_set($pdo, 'require_on_save', $on)) {
            oatReply(['success' => false, 'message' => '設定儲存失敗']);
        }
        oatReply(['success' => true, 'on' => $on,
                  'message' => $on ? '已開啟：新增/編輯訂單存檔時必須先選製程標籤' : '已關閉：存檔時不強制選擇製程標籤']);
    }

    // ── 補設定：總覽數字 ──────────────────────────────────────────────
    case 'backfill_summary': {
        if (!oatCanSetting($pdo, $uid)) oatDeny();
        oatReply(['success' => true, 'summary' => ot_astag_backfill_summary($pdo)]);
    }

    // ── 補設定：依製程文字分組（一組一組設，最省事）──────────────────────
    case 'backfill_groups': {
        if (!oatCanSetting($pdo, $uid)) oatDeny();
        $f = [
            'year'              => (string)($_POST['year'] ?? 'ALL'),
            'kw'                => (string)($_POST['kw'] ?? ''),
            'include_cancelled' => !empty($_POST['include_cancelled']) ? 1 : 0,
            // 2026-10-06 補上：原本漏了這兩個旗標，前端「顯示：全部（含已設定）」切過去
            // 分組模式完全沒反應，只有逐筆模式吃得到（真正的 gap 在這裡，不只是 UI 沒露出入口）
            'include_tagged'    => !empty($_POST['include_tagged']) ? 1 : 0,
            'only_tag'          => (string)($_POST['only_tag'] ?? ''),
        ];
        $limit = (int)($_POST['limit'] ?? 200);
        $g = ot_astag_backfill_groups($pdo, $f, $limit);
        oatReply(['success' => true, 'rows' => $g['rows'], 'total_groups' => $g['total_groups'],
                  'summary' => ot_astag_backfill_summary($pdo),
                  'options_all' => ot_astag_options($pdo, true)]);
    }

    // ── 補設定：逐筆清單（分頁；可限定某一組製程文字）────────────────────
    case 'backfill_orders': {
        if (!oatCanSetting($pdo, $uid)) oatDeny();
        $f = [
            'year'              => (string)($_POST['year'] ?? 'ALL'),
            'kw'                => (string)($_POST['kw'] ?? ''),
            'include_cancelled' => !empty($_POST['include_cancelled']) ? 1 : 0,
            // 要改已經綁定好的時才帶（2026-10-02）；只是「看得到」，要真的改還要再帶 overwrite
            'include_tagged'    => !empty($_POST['include_tagged']) ? 1 : 0,
            'only_tag'          => (string)($_POST['only_tag'] ?? ''),
        ];
        if (isset($_POST['pi_exact'])) $f['pi_exact'] = (string)$_POST['pi_exact'];
        $r = ot_astag_backfill_orders($pdo, $f, (int)($_POST['page'] ?? 1), (int)($_POST['per'] ?? 20));
        oatReply(['success' => true] + $r + ['options_all' => ot_astag_options($pdo, true)]);
    }

    // ── 補設定：套用（只填空白、永不覆蓋已設定的）────────────────────────
    case 'backfill_apply': {
        if (!oatCanSetting($pdo, $uid)) oatDeny();
        $tagId = (int)($_POST['tag_id'] ?? 0);
        $scope = (string)($_POST['scope'] ?? '');
        $f = [
            'year'              => (string)($_POST['year'] ?? 'ALL'),
            'kw'                => (string)($_POST['kw'] ?? ''),
            'include_cancelled' => !empty($_POST['include_cancelled']) ? 1 : 0,
            'include_tagged'    => !empty($_POST['include_tagged']) ? 1 : 0,
            'only_tag'          => (string)($_POST['only_tag'] ?? ''),
            // overwrite＝真的改掉已經設定好的（不可逆，所以跟 include_tagged 分兩個旗標）
            'overwrite'         => !empty($_POST['overwrite']) ? 1 : 0,
        ];
        if (isset($_POST['pi_exact'])) $f['pi_exact'] = (string)$_POST['pi_exact'];
        $ids = [];
        if (!empty($_POST['order_ids'])) {
            $d = json_decode((string)$_POST['order_ids'], true);
            if (is_array($d)) $ids = $d;
            if (count($ids) > 2000) oatReply(['success' => false, 'message' => '一次最多補設定 2000 張訂單']);
        }
        $r = ot_astag_backfill_apply($pdo, $tagId, $scope, $f, $ids, $uid, $uname);
        oatReply(['success' => (bool)$r['ok'], 'applied' => $r['applied'], 'message' => $r['msg'],
                  'summary' => ot_astag_backfill_summary($pdo)]);
    }

    // ── 清除「被設成某個標籤」的全部訂單綁定（2026-10-02 使用者要求：設錯了要能重來）──
    // 標籤定義不動，只是把訂單退回「尚未設定」；每一張都會留逐筆歷程。
    case 'clear_tag': {
        if (!oatCanSetting($pdo, $uid)) oatDeny();
        $r = ot_astag_clear_tag($pdo, (int)($_POST['tag_id'] ?? 0), (string)($_POST['scope'] ?? ''), $uid, $uname);
        oatReply(['success' => (bool)$r['ok'], 'cleared' => $r['cleared'], 'message' => $r['msg'],
                  'usage' => ot_astag_usage($pdo), 'summary' => ot_astag_backfill_summary($pdo)]);
    }

    default:
        oatReply(['success' => false, 'message' => '無效的操作：' . $action]);
}
