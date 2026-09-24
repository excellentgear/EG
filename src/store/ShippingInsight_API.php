<?php
/**
 * ShippingInsight_API.php — 出貨分析（views/Sales/Shipping_Insight.php）唯一資料端點
 * 建立：2026-09-24（使用者交辦：比照訂單分析做一份新的出貨分析頁面）
 *
 * 權限沿用既有「shipping」模組角色（src/common/shipping_lib.php 的 sq_perms()）：
 *   canView  ＝ shipping_view／shipping_edit／shipping_admin／系統管理員 → 可檢視本頁
 *   canAdmin ＝ shipping_admin／系統管理員 → 可改設定（月份截止日／KPI提醒／移動平均監控／確認異常）
 * 刻意不另開新角色：這是既有「出貨」業務範圍內的分析畫面，另開一套角色只會讓管理員
 * 要在兩個地方各指派一次（比照 order_analysis 沿用 order_track 模組角色的作法）。
 * 出貨性質主檔的新增/修改/刪除沿用既有共用端點 src/store/manage_sale_types.php，不重刻一份。
 *
 * 一律即時算、不做快照：出貨隨時在改，存起來的數字只會停在存檔那天。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/shipping_lib.php';
include_once $document_root . '/EGsystem/src/common/shipping_insight_lib.php';
include_once $document_root . '/EGsystem/src/common/client_quarter_lib.php';

set_exception_handler(function ($e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '伺服器錯誤：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
});

function siOut(array $a = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true], $a), JSON_UNESCAPED_UNICODE);
    exit;
}
function siErr(string $msg, int $code = 400, array $extra = []) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sqUser = sq_current_user($db);
if (!$sqUser) siErr('未登入或連線已逾時，請重新登入', 401);
$perms = sq_perms($db, $sqUser);
if (!$perms['canView']) siErr('您沒有出貨分析的檢視權限，請洽管理員指派 shipping_view 以上角色', 403);
$canAdmin = (bool)$perms['canAdmin'];

if (empty($_SESSION['si_csrf'])) $_SESSION['si_csrf'] = bin2hex(random_bytes(16));
$action = $_GET['action'] ?? $_POST['action'] ?? '';

$WRITE = ['settings_save', 'cutoff_save', 'anomaly_confirm'];
if (in_array($action, $WRITE, true)) {
    $tok = $_POST['csrf'] ?? '';
    if (!is_string($tok) || $tok === '' || !hash_equals((string)$_SESSION['si_csrf'], $tok)) {
        siErr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 403, ['code' => 'CSRF']);
    }
    if (!$canAdmin) siErr('您沒有出貨分析設定的權限（需 shipping_admin）', 403);
}

/** 一次解析共用篩選參數（年度／期間／出貨性質／客戶），各 action 共用一份規則 */
function siParseCommon(array $q): array {
    $gran = (string)($q['gran'] ?? 'quarter');
    if (!isset(oa_grans()[$gran])) siErr('不合法的期間粒度');
    $cmp = (string)($q['cmp'] ?? 'yoy');
    if (!isset(oa_compares()[$cmp])) siErr('不合法的比較基準');
    $clients = $q['clients'] ?? '';
    if (is_string($clients)) {
        $d = json_decode($clients, true);
        $clients = is_array($d) ? $d : array_filter(array_map('trim', explode(',', $clients)));
    }
    $saleTypes = $q['sale_types'] ?? [];
    if (is_string($saleTypes)) {
        $d = json_decode($saleTypes, true);
        $saleTypes = is_array($d) ? $d : [];
    }
    return [
        'year' => (int)($q['year'] ?? date('Y')), 'gran' => $gran, 'idx' => (int)($q['idx'] ?? 1),
        'cmp' => $cmp, 'align' => array_key_exists('align', $q) ? (int)$q['align'] : 1,
        'clients' => array_values((array)$clients), 'sale_types' => array_values((array)$saleTypes),
    ];
}

switch ($action) {

    /* ── 分析主體 ─────────────────────────────────────────────── */
    case 'analyze': {
        $p = siParseCommon($_REQUEST);
        $res = si_report($db, array_merge($p, ['top' => 50]));
        $kpiAlert = si_kpi_alert($db);
        $ma = si_moving_avg($db, []);
        $res['insights']  = si_insights($db, $res, $kpiAlert, $ma);
        $res['kpi_alert'] = $kpiAlert;
        $res['ma']        = $ma;
        $res['perm'] = ['canAdmin' => $canAdmin ? 1 : 0, 'isAdmin' => $perms['isAdmin'] ? 1 : 0];
        siOut($res);
    }

    /* ── 客戶清單（多選比較用；只列這個年度真的有出貨/訂單/退貨的客戶）─ */
    case 'clients': {
        $year = max(2000, min(2100, (int)($_REQUEST['year'] ?? date('Y'))));
        $resolve = cqa_client_resolver($db);
        $from = $year . '-01-01'; $to = $year . '-12-31';
        $ship = si_fetch_ship($db, $from, $to, ['resolver' => $resolve]);
        $ord  = oa_fetch_orders($db, $from, $to, ['basis' => 'order', 'include_paused' => false]);
        $m = [];
        foreach ($ship as $r) {
            $k = $r['ckey'];
            if (!isset($m[$k])) $m[$k] = ['key' => $k, 'name' => $r['cname'], 'cid' => $r['cid'], 'bad' => $r['cbad'], 'ship_rows' => 0, 'ord_rows' => 0];
            $m[$k]['ship_rows']++;
        }
        foreach ($ord as $r) {
            $k = $r['ckey'];
            if (!isset($m[$k])) $m[$k] = ['key' => $k, 'name' => $r['cname'], 'cid' => $r['cid'], 'bad' => $r['cbad'], 'ship_rows' => 0, 'ord_rows' => 0];
            $m[$k]['ord_rows']++;
        }
        $list = array_values($m);
        usort($list, function ($a, $b) { return ($b['ship_rows'] + $b['ord_rows']) <=> ($a['ship_rows'] + $a['ord_rows']); });
        siOut(['clients' => $list, 'year' => $year]);
    }

    /* ── 出貨性質（給篩選下拉；CRUD 一律走 manage_sale_types.php）───── */
    case 'sale_types': {
        siOut(['sale_types' => si_sale_types($db)]);
    }

    /* ── 明細清單：出貨／退貨／訂單（只列「目前這個期間」，後端分頁）── */
    case 'list_ship':
    case 'list_return':
    case 'list_order': {
        $p = siParseCommon($_REQUEST);
        $cur = oa_period_pick($p['year'], $p['gran'], $p['idx']);
        $kw  = trim((string)($_REQUEST['kw'] ?? ''));
        $page = max(1, (int)($_REQUEST['page'] ?? 1));
        $per  = max(10, min(200, (int)($_REQUEST['per'] ?? 50)));
        $resolve = cqa_client_resolver($db);

        if ($action === 'list_ship') {
            $rows = si_fetch_ship($db, $cur['start'], $cur['end'], ['sale_types' => si_sale_types_whitelist($db, $p['sale_types']), 'resolver' => $resolve]);
        } elseif ($action === 'list_return') {
            $rows = si_fetch_return($db, $cur['start'], $cur['end'], ['resolver' => $resolve]);
        } else {
            $rows = oa_fetch_orders($db, $cur['start'], $cur['end'], ['basis' => 'order', 'include_paused' => false]);
        }
        if ($p['clients']) {
            $selMap = array_flip($p['clients']);
            $rows = array_values(array_filter($rows, function ($r) use ($selMap) { return isset($selMap[$r['ckey']]); }));
        }
        if ($kw !== '') {
            $kwL = mb_strtolower($kw, 'UTF-8');
            $rows = array_values(array_filter($rows, function ($r) use ($kwL) {
                $hay = mb_strtolower(($r['cname'] ?? '') . ' ' . ($r['pno'] ?? '') . ' ' . ($r['no'] ?? ''), 'UTF-8');
                return mb_strpos($hay, $kwL, 0, 'UTF-8') !== false;
            }));
        }
        // 依日期新到舊排序，較方便查看最近的異動
        usort($rows, function ($a, $b) { return strcmp($b['dt'], $a['dt']); });
        $total = count($rows);
        $rows = array_slice($rows, ($page - 1) * $per, $per);
        siOut(['rows' => $rows, 'total' => $total, 'page' => $page, 'per' => $per, 'period' => $cur]);
    }

    /* ── 客戶統計（該期間逐客戶彙總，供「客戶統計」分頁）──────────── */
    case 'list_customer': {
        $p = siParseCommon($_REQUEST);
        $res = si_report($db, array_merge($p, ['top' => 500]));
        $rows = $res['clients'];
        $kw = trim((string)($_REQUEST['kw'] ?? ''));
        if ($kw !== '') {
            $kwL = mb_strtolower($kw, 'UTF-8');
            $rows = array_values(array_filter($rows, function ($r) use ($kwL) {
                return mb_strpos(mb_strtolower($r['name'], 'UTF-8'), $kwL, 0, 'UTF-8') !== false;
            }));
        }
        usort($rows, function ($a, $b) { return $b['cur']['net_amount'] <=> $a['cur']['net_amount']; });
        siOut(['rows' => $rows, 'meta' => $res['meta']]);
    }

    /* ── 帳款月份截止日 ───────────────────────────────────────── */
    case 'cutoff_get': {
        siOut(['cutoff_day' => si_cutoff_get($db), 'csrf' => $_SESSION['si_csrf']]);
    }
    case 'cutoff_save': {
        $day = (int)($_POST['cutoff_day'] ?? -1);
        if ($day < 0 || $day > 31) siErr('截止日必須介於 0~31');
        si_cutoff_save($db, $day, (string)($_SESSION['userName'] ?? ''));
        siOut(['cutoff_day' => si_cutoff_get($db), 'message' => $day > 0 ? "已設為每月 {$day} 日截止" : '已取消截止日設定']);
    }

    /* ── 異常偵測：清單（分頁）與確認 ─────────────────────────── */
    case 'anomaly_list': {
        $p = siParseCommon($_REQUEST);
        $cur = oa_period_pick($p['year'], $p['gran'], $p['idx']);
        $onlyUnconfirmed = !isset($_REQUEST['all']) || !$_REQUEST['all'];
        $resolve = cqa_client_resolver($db);
        $rows = si_fetch_ship($db, $cur['start'], $cur['end'], ['sale_types' => si_sale_types_whitelist($db, $p['sale_types']), 'resolver' => $resolve]);
        $rows = array_values(array_filter($rows, function ($r) use ($onlyUnconfirmed) {
            return $r['anomaly'] && (!$onlyUnconfirmed || !$r['anomaly_confirmed']);
        }));
        usort($rows, function ($a, $b) { return strcmp($b['dt'], $a['dt']); });
        $page = max(1, (int)($_REQUEST['page'] ?? 1));
        $per  = max(10, min(200, (int)($_REQUEST['per'] ?? 50)));
        $total = count($rows);
        // 依出貨性質分組彙總（畫面上的分組報告，比逐筆看更快抓到「哪一種性質最常出問題」）
        $byType = [];
        foreach ($rows as $r) {
            $k = $r['st_name'];
            if (!isset($byType[$k])) $byType[$k] = ['name' => $k, 'count' => 0, 'amount' => 0.0];
            $byType[$k]['count']++; $byType[$k]['amount'] += $r['amount'];
        }
        siOut(['rows' => array_slice($rows, ($page - 1) * $per, $per), 'total' => $total,
               'page' => $page, 'per' => $per, 'by_type' => array_values($byType), 'period' => $cur,
               'csrf' => $_SESSION['si_csrf']]);
    }
    case 'anomaly_confirm': {
        $ids = json_decode((string)($_POST['ids'] ?? '[]'), true);
        if (!is_array($ids) || !$ids) siErr('請至少選擇一筆');
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $confirm = !empty($_POST['confirm']) ? 1 : 0;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("UPDATE is_list SET anomaly_confirmed=? WHERE IS_id IN ($in)");
        $st->execute(array_merge([$confirm], $ids));
        siOut(['updated' => $st->rowCount(), 'confirm' => $confirm]);
    }

    /* ── 設定（KPI 提醒／移動平均監控）───────────────────────── */
    case 'settings_get': {
        siOut(['settings' => si_settings($db), 'defaults' => si_settings_default(), 'canAdmin' => $canAdmin ? 1 : 0, 'csrf' => $_SESSION['si_csrf']]);
    }
    case 'settings_save': {
        $in = json_decode((string)($_POST['settings'] ?? '{}'), true);
        if (!is_array($in)) siErr('設定格式不正確');
        $sv = si_settings_save($db, $in, (string)($_SESSION['userName'] ?? ''));
        if (empty($sv['ok'])) siErr(implode("\n", $sv['errors']), 400, ['errors' => $sv['errors']]);
        siOut(['settings' => $sv['settings']]);
    }
    case 'ma_preview': {
        $opt = [];
        foreach (['months', 'consecutive'] as $k) if (isset($_REQUEST[$k]) && $_REQUEST[$k] !== '') $opt[$k] = (int)$_REQUEST[$k];
        $opt['show'] = 18;
        siOut(['ma' => si_moving_avg($db, $opt)]);
    }

    /* 收通知的人員候選：一律走全站共用 people_lib */
    case 'users': {
        require_once $document_root . '/EGsystem/src/common/people_lib.php';
        $list = [];
        foreach (eg_people_list($db, ['multi_dept' => true]) as $p2) {
            $list[] = ['id' => (int)$p2['id'], 'name' => (string)($p2['user_cname'] ?: $p2['user_uname']),
                       'dept' => (string)($p2['dept_name'] ?? ''), 'post' => (string)($p2['position_name'] ?? '')];
        }
        siOut(['users' => $list]);
    }

    /* ── 客戶季度分析（唯一實作 client_quarter_lib.php，本頁只是薄包裝）─
       契約與 Shipping_Analysis_new.php 的 cq_quarters/cq_growth 完全相同，
       方便日後兩頁互相參照；計算一律呼叫同一支共用庫，不在這裡另算一次。 */
    case 'cq_quarters':
    case 'cq_growth': {
        $ob = $_REQUEST['order_basis'] ?? 'delivery';
        $qb = $_REQUEST['q_basis'] ?? 'billing';
        $nowY = (int)date('Y');
        $saleTypes = si_sale_types_whitelist($db, $_REQUEST['sale_types'] ?? []);

        if ($action === 'cq_quarters') {
            $yTo = max(2000, min(2100, (int)($_REQUEST['year'] ?? $nowY)));
            $back = max(0, min(4, (int)($_REQUEST['years_back'] ?? 1)));
            $yFrom = $yTo - $back;
            $d = cqa_quarter_rows($db, ['year_from' => $yFrom, 'year_to' => $yTo, 'order_basis' => $ob, 'q_basis' => $qb, 'sale_types' => $saleTypes]);
            $clients = [];
            foreach ($d['clients'] as $k => $c) {
                $q = [];
                foreach ($c['q'] as $qk => $v) {
                    $q[$qk] = ['ship' => round($v['ship']), 'ret' => round($v['ret']), 'ord' => round($v['ord']),
                               'net' => round($v['net']), 'ship_qty' => round($v['ship_qty'], 2), 'ret_qty' => round($v['ret_qty'], 2),
                               'ship_cnt' => $v['ship_cnt'], 'ret_cnt' => $v['ret_cnt'], 'ord_cnt' => $v['ord_cnt']];
                }
                $clients[] = ['key' => $k, 'name' => $c['name'], 'cid' => $c['cid'], 'unmatched' => $c['unmatched'] ? 1 : 0,
                              'total' => ['ship' => round($c['total']['ship']), 'ret' => round($c['total']['ret']),
                                          'ord' => round($c['total']['ord']), 'net' => round($c['total']['net'])], 'q' => $q];
            }
            $prog = [];
            foreach ($d['quarters'] as $qk) {
                [$py, $pq] = cqa_qparse($qk);
                $pr = cqa_quarter_progress($db, $py, $pq, $qb, $d['meta']['cutoff']);
                $prog[$qk] = ['start' => $pr['start'], 'end' => $pr['end'], 'days_total' => $pr['days_total'],
                              'days_done' => $pr['days_done'], 'is_partial' => $pr['is_partial'] ? 1 : 0, 'is_future' => $pr['is_future'] ? 1 : 0];
            }
            siOut(['clients' => $clients, 'quarters' => $d['quarters'], 'progress' => $prog, 'order_quality' => $d['order_quality'], 'meta' => $d['meta']]);
        }
        $g = cqa_growth($db, ['year' => (int)($_REQUEST['year'] ?? $nowY), 'q' => (int)($_REQUEST['q'] ?? 1),
                              'compare' => $_REQUEST['compare'] ?? 'yoy', 'metric' => $_REQUEST['metric'] ?? 'net',
                              'min_amt' => (float)($_REQUEST['min_amt'] ?? 50000),
                              'align' => !isset($_REQUEST['align']) || $_REQUEST['align'] === '1',
                              'order_basis' => $ob, 'q_basis' => $qb, 'sale_types' => $saleTypes]);
        foreach ($g['rows'] as &$gr) $gr['unmatched'] = $gr['unmatched'] ? 1 : 0;
        unset($gr);
        siOut($g);
    }

    default:
        siErr('無效的操作');
}
