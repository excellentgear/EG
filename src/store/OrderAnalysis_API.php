<?php
/**
 * OrderAnalysis_API.php — 訂單分析（views/Sales/Order_Analysis.php）唯一資料端點
 * 建立：2026-09-22（使用者交辦）
 *
 * 權限沿用訂單追蹤模組（roles module='order_track'，功能碼在 NewOrder_Track.php 的 $OT_PAGE_FEATURES）：
 *   ot_analysis         訂單分析（檢視）
 *   ot_analysis_setting 訂單分析設定（數量區間、全製／單製關鍵字）
 * 刻意不另開一個新模組／新角色：這一頁本來就是訂單追蹤的分析畫面，
 * 另開一套角色只會讓管理員要在兩個地方各指派一次，遲早有人漏掉。
 * 前端擋一次、後端同規則再擋一次（鐵律8）。
 *
 * 一律即時算、不做快照：訂單隨時在改，存起來的數字只會永遠停在存檔那天而且看不出它是舊的。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/order_analysis_lib.php';
include_once $document_root . '/EGsystem/src/common/role_features_helper.php';

// 未捕捉的例外一律轉成 JSON——不然畫面上只會是「按了完全沒反應」的空白 500
set_exception_handler(function ($e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '伺服器錯誤：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
});

function oaOut(array $a = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true], $a), JSON_UNESCAPED_UNICODE);
    exit;
}
function oaErr(string $msg, int $code = 400, array $extra = []) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$uid = (int)($_SESSION['id'] ?? 0);
if (!$uid) oaErr('未登入或連線已逾時，請重新登入', 401);

$feat     = rf_load_user_features_all($db, $uid);
$isAdmin  = in_array('all', $feat, true);
$canView  = $isAdmin || in_array('ot_analysis', $feat, true);
$canSet   = $isAdmin || in_array('ot_analysis_setting', $feat, true);
if (!$canView) oaErr('您沒有訂單分析的檢視權限，請洽管理員於訂單追蹤的「角色設定」開通 ot_analysis', 403);

if (empty($_SESSION['oa_csrf'])) $_SESSION['oa_csrf'] = bin2hex(random_bytes(16));
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 寫入類先驗登入（上面已驗）再驗 CSRF；順序不可顛倒，理由同其他模組：
// session 被 GC 掃掉時 token 會在同一個請求裡重新產生、比對必定不過，那是「已被登出」不是 CSRF 攻擊
$WRITE = ['settings_save'];
if (in_array($action, $WRITE, true)) {
    $tok = $_POST['csrf'] ?? '';
    if (!is_string($tok) || $tok === '' || !hash_equals((string)$_SESSION['oa_csrf'], $tok)) {
        oaErr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 403, ['code' => 'CSRF']);
    }
}

switch ($action) {

    /* ── 分析主體 ─────────────────────────────────────────────── */
    case 'analyze': {
        $clients = $_POST['clients'] ?? $_GET['clients'] ?? '';
        if (is_string($clients)) {
            $d = json_decode($clients, true);
            $clients = is_array($d) ? $d : array_filter(array_map('trim', explode(',', $clients)));
        }
        // 口徑參數一律過白名單，不吃前端隨便送的字串（那幾個值會被拼進 SQL 的欄位名）
        $gran  = (string)($_REQUEST['gran']  ?? 'quarter');
        $basis = (string)($_REQUEST['basis'] ?? 'order');
        $cmp   = (string)($_REQUEST['cmp']   ?? 'yoy');
        if (!isset(oa_grans()[$gran]))      oaErr('不合法的期間粒度');
        if (!isset(oa_date_bases()[$basis])) oaErr('不合法的日期基準');
        if (!isset(oa_compares()[$cmp]))    oaErr('不合法的比較基準');
        $rm = (string)($_REQUEST['rank_metric'] ?? '');
        if ($rm !== '' && !in_array($rm, ['amount', 'qty', 'orders'], true)) oaErr('不合法的排序口徑');

        $res = oa_analyze($db, [
            'year'           => (int)($_REQUEST['year'] ?? date('Y')),
            'gran'           => $gran,
            'idx'            => (int)($_REQUEST['idx'] ?? 1),
            'basis'          => $basis,
            'cmp'            => $cmp,
            'align'          => array_key_exists('align', $_REQUEST) ? (int)$_REQUEST['align'] : 1,
            'include_paused' => !empty($_REQUEST['include_paused']),
            'top'            => (int)($_REQUEST['top'] ?? 20),
            'rank_metric'    => $rm !== '' ? $rm : null,
            'clients'        => array_values((array)$clients),
        ]);
        $res['perm'] = ['canSet' => $canSet ? 1 : 0, 'isAdmin' => $isAdmin ? 1 : 0];
        oaOut($res);
    }

    /* ── 客戶清單（多選比較用）──────────────────────────────────
       只列「這個年度真的有訂單的客戶」＋各自的筆數，不是把 980 家主檔整份攤開
       ——候選清單如果只是主檔全表，使用者根本挑不到他要比的那幾家。 */
    case 'clients': {
        $year  = max(2000, min(2100, (int)($_REQUEST['year'] ?? date('Y'))));
        $basis = (string)($_REQUEST['basis'] ?? 'order');
        if (!isset(oa_date_bases()[$basis])) oaErr('不合法的日期基準');
        $rows = oa_fetch_orders($db, $year . '-01-01', $year . '-12-31',
                                ['basis' => $basis, 'include_paused' => !empty($_REQUEST['include_paused'])]);
        $m = [];
        foreach ($rows as $r) {
            $k = $r['ckey'];
            if (!isset($m[$k])) $m[$k] = ['key' => $k, 'name' => $r['cname'], 'cid' => $r['cid'],
                                          'bad' => $r['cbad'], 'orders' => 0, 'qty' => 0, 'amount' => 0.0];
            $m[$k]['orders']++; $m[$k]['qty'] += $r['qty']; $m[$k]['amount'] += $r['amount'];
        }
        $list = array_values($m);
        usort($list, function ($a, $b) { return $b['orders'] <=> $a['orders']; });
        oaOut(['clients' => $list, 'year' => $year]);
    }

    /* ── 設定（數量區間／全製單製關鍵字）─────────────────────── */
    case 'settings_get': {
        oaOut([
            'bands'    => oa_qty_bands($db),
            'rules'    => oa_proc_rules($db),
            'fallback' => oa_proc_fallback($db),
            'defaults' => ['bands' => oa_qty_bands_default(), 'rules' => oa_proc_rules_default()],
            'canSet'   => $canSet ? 1 : 0,
            'csrf'     => $_SESSION['oa_csrf'],
        ]);
    }

    case 'settings_save': {
        if (!$canSet) oaErr('您沒有訂單分析設定的權限（ot_analysis_setting）', 403);
        $bands = json_decode((string)($_POST['bands'] ?? '[]'), true);
        $rules = json_decode((string)($_POST['rules'] ?? '[]'), true);
        if (!is_array($bands) || !is_array($rules)) oaErr('設定格式不正確');
        $fb = (string)($_POST['fallback'] ?? 'single');
        if (!in_array($fb, ['full', 'single', 'unknown'], true)) oaErr('不合法的「未命中規則時」設定');

        // 前端已即時驗過一次，後端用同一支函式再驗一次（鐵律8）
        $nb = oa_qty_bands_norm($bands);
        $nr = oa_proc_rules_norm($rules);
        $errs = array_merge(
            array_map(function ($e) { return '數量區間：' . $e; }, $nb['errors']),
            array_map(function ($e) { return '製程規則：' . $e; }, $nr['errors'])
        );
        if ($errs) oaErr(implode("\n", $errs), 400, ['errors' => $errs]);

        $uname = (string)($_SESSION['userName'] ?? $uid);
        $db->beginTransaction();
        try {
            oa_param_save($db, 'qty_bands',     $nb['bands'], $uname);
            oa_param_save($db, 'proc_rules',    $nr['rules'], $uname);
            oa_param_save($db, 'proc_fallback', $fb,          $uname);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            oaErr('儲存失敗：' . $e->getMessage(), 500);
        }
        oaOut(['bands' => $nb['bands'], 'rules' => $nr['rules'], 'fallback' => $fb]);
    }

    default:
        oaErr('無效的操作');
}
