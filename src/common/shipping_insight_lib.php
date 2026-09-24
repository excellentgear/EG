<?php
/**
 * shipping_insight_lib.php — 出貨分析（views/Sales/Shipping_Insight.php）唯一實作
 * 建立：2026-09-24（使用者交辦：比照訂單分析做一份出貨分析新頁面）
 *
 * 使用端：views/Sales/Shipping_Insight.php（畫面）／src/store/ShippingInsight_API.php（資料）
 * 這是一個全新的獨立頁面（使用者明確要求「不是修改舊的」），不動 Shipping_Analysis_new.php
 * 一行程式碼；共用的只有「同一份資料庫欄位」與「已經是全站唯一實作」的既有函式庫：
 *   - client_quarter_lib.php：客戶歸戶（cqa_client_resolver）、出貨性質篩選（cqa_sale_type_sql）、
 *     帳款月份截止日（cqa_cutoff_day）、客戶季度分析（cqa_quarter_rows／cqa_growth）
 *   - order_analysis_lib.php：期間切法（oa_period_buckets 等）、訂單資料（oa_fetch_orders）、
 *     客戶「第一次出現」判定（oa_client_first_seen）——這些都是通用計算，不是「訂單」專屬邏輯，
 *     兩邊各寫一份遲早算出不同答案（鐵律4）。
 *   - is_sale_type／manage_sale_types.php：出貨性質主檔，CRUD 直接沿用既有共用端點，不重刻一份。
 *
 * ══════════════════════════════════════════════════════════════════════
 * 資料事實（不講出來會被當成程式壞掉）
 * ══════════════════════════════════════════════════════════════════════
 * 1) 出貨金額＝Qty×Unit_price，一律先套「出貨性質」篩選（cqa_sale_type_sql）；
 *    沒有明確篩選時預設排除 is_count=0（樣品／補件等不列入統計）。
 * 2) 訂單金額只算得出「有填單價」的訂單（沿用 order_analysis_lib 的既有事實，
 *    2024～2025 年幾乎沒人填單價），畫面上一定要印覆蓋率。
 * 3) 客戶歸戶三張表口徑不同（is_list.Client_id 幾乎全空、ir_track 完全沒有客戶編號欄位、
 *    order_track.Client_name_ID 大多有值），一律呼叫 cqa_client_resolver() 正規化成同一把鍵。
 * 4) 「新客戶」＝在系統整段歷史（出貨／訂單／退貨）第一次出現就落在本期；
 *    只是「比較基期剛好沒有」的叫「回流客戶」，不是新客戶（同 order_analysis_lib 的判定）。
 */

require_once __DIR__ . '/client_quarter_lib.php';
require_once __DIR__ . '/order_analysis_lib.php';

if (!defined('SI_PARAM_GROUP')) define('SI_PARAM_GROUP', 'SHIPPING_INSIGHT');

/* ══════════════════════════════════════════════════════════════════
 * 設定值（system_parameters；沿用 order_analysis_lib 同一種存放方式）
 * ══════════════════════════════════════════════════════════════════ */
function si_param_get(PDO $db, string $key, $default)
{
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
        $st->execute([SI_PARAM_GROUP, $key]);
        $v = $st->fetchColumn();
        if ($v === false || $v === null || $v === '') return $default;
        $d = json_decode((string)$v, true);
        return $d === null ? $default : $d;
    } catch (Throwable $e) { return $default; }
}
function si_param_save(PDO $db, string $key, $val, string $by = ''): void
{
    $json = json_encode($val, JSON_UNESCAPED_UNICODE);
    $st = $db->prepare("SELECT id FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
    $st->execute([SI_PARAM_GROUP, $key]);
    $rid = $st->fetchColumn();
    if ($rid) {
        $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=?, updated_at=NOW() WHERE id=?")
           ->execute([$json, $by, $rid]);
    } else {
        $db->prepare("INSERT INTO system_parameters (param_group,param_key,param_value,description,updated_by,updated_at)
                      VALUES (?,?,?,?,?,NOW())")
           ->execute([SI_PARAM_GROUP, $key, $json, '出貨分析：' . $key, $by]);
    }
}

/* ══════════════════════════════════════════════════════════════════
 * 帳款月份截止日（全站共用鍵 billing_cutoff_day，讀走 cqa_cutoff_day，
 * 存另開一支——Shipping_Analysis_new.php 那支存檔是內嵌在該頁面自己的
 * PHP 裡，不是獨立 API，本頁刻意不去 POST 那一頁避免耦合到非 API 檔案）
 * ══════════════════════════════════════════════════════════════════ */
function si_cutoff_get(PDO $db): int { return cqa_cutoff_day($db); }
function si_cutoff_save(PDO $db, int $day, string $by): void
{
    $day = max(0, min(31, $day));
    $db->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by, updated_at)
                  VALUES ('billing_cutoff_day', ?, ?, NOW())
                  ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
                      updated_by=VALUES(updated_by), updated_at=NOW()")
       ->execute([$day, $by]);
}

/* ══════════════════════════════════════════════════════════════════
 * 出貨性質（主檔本身的 CRUD 已有共用端點 src/store/manage_sale_types.php，
 * 這裡只需要「讀出來給下拉用」與白名單過濾）
 * ══════════════════════════════════════════════════════════════════ */
function si_sale_types(PDO $db): array
{
    try {
        return $db->query("SELECT * FROM is_sale_type WHERE is_active=1 ORDER BY sort_order, sale_type_id")
                   ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}
/** 前端送來的出貨性質值一律過白名單（'NULL' 或存在的 sale_type_id），不採信原始輸入 */
function si_sale_types_whitelist(PDO $db, $raw): array
{
    if (!is_array($raw)) return [];
    $valid = [];
    try {
        foreach ($db->query("SELECT sale_type_id FROM is_sale_type") as $r) $valid[(string)$r['sale_type_id']] = 1;
    } catch (Throwable $e) {}
    $out = [];
    foreach ($raw as $v) {
        $v = trim((string)$v);
        if ($v === 'NULL' || isset($valid[$v])) $out[] = $v;
    }
    return $out;
}

/* ══════════════════════════════════════════════════════════════════
 * 取出貨／退貨資料並正規化（客戶歸戶、料號、金額、異常判定）
 * ══════════════════════════════════════════════════════════════════ */
function si_fetch_ship(PDO $db, string $from, string $to, array $opt = []): array
{
    $saleTypes = $opt['sale_types'] ?? null;
    $resolve   = $opt['resolver'] ?? cqa_client_resolver($db);
    $stCond    = cqa_sale_type_sql($saleTypes);

    $sql = "SELECT isl.IS_id, isl.IS_number, isl.Order_date, isl.Client_id, isl.Client_name,
                   isl.d_setting_id, isl.Product_id, isl.Specification, isl.Qty, isl.Unit_price,
                   isl.Order_id, isl.Note, isl.sale_type, isl.anomaly_confirmed,
                   ist.sale_type_name, ist.is_count, ist.exclude_anomaly, ist.exclude_when_nonzero
              FROM is_list isl
              LEFT JOIN is_sale_type ist ON isl.sale_type = ist.sale_type_id
             WHERE isl.Order_date BETWEEN ? AND ? AND $stCond";
    $st = $db->prepare($sql);
    $st->execute([$from, $to]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $qty   = (int)$r['Qty'];
        $price = (float)$r['Unit_price'];
        $amt   = $qty * $price;
        $c = $resolve($r['Client_id'], (string)$r['Client_name']);

        $isCount  = $r['is_count'] === null ? null : (int)$r['is_count'];
        $exAnom   = (int)($r['exclude_anomaly'] ?? 0);
        $exNZ     = (int)($r['exclude_when_nonzero'] ?? 0);
        $anomaly  = false; $reason = '';
        if ($exAnom) {
            // 該性質本來就不做異常偵測
        } elseif ($exNZ) {
            if ($amt > 0) { $anomaly = true; $reason = '此出貨性質預期金額為 0，卻登記了金額'; }
        } else {
            if ($amt <= 0 && ($isCount === null || $isCount === 1)) { $anomaly = true; $reason = '金額為 0（可能漏填單價）'; }
        }

        $out[] = [
            'id'      => (int)$r['IS_id'],
            'no'      => (string)$r['IS_number'],
            'dt'      => substr((string)$r['Order_date'], 0, 10),
            'ckey'    => $c['key'], 'cname' => $c['name'], 'cid' => (string)$c['cid'], 'cbad' => $c['unmatched'] ? 1 : 0,
            'pid'     => (int)($r['d_setting_id'] ?? 0),
            'pno'     => trim((string)$r['Product_id']) ?: '（未填料號）',
            'spec'    => (string)($r['Specification'] ?? ''),
            'qty'     => $qty, 'price' => $price, 'amount' => $amt,
            'order_id'=> (int)($r['Order_id'] ?? 0),
            'note'    => (string)($r['Note'] ?? ''),
            'st_id'   => $r['sale_type'] === null ? null : (int)$r['sale_type'],
            'st_name' => (string)($r['sale_type_name'] ?? '一般產品'),
            'is_count'=> $isCount,
            'anomaly' => $anomaly ? 1 : 0,
            'anomaly_reason'    => $reason,
            'anomaly_confirmed' => (int)($r['anomaly_confirmed'] ?? 0),
        ];
    }
    return $out;
}

function si_fetch_return(PDO $db, string $from, string $to, array $opt = []): array
{
    $resolve = $opt['resolver'] ?? cqa_client_resolver($db);
    $sql = "SELECT IR_id, IR_no, IR_date, Client_name, d_setting_id, d_id, Specification,
                   Qty, Unit_price, IR_ps, return_type_id
              FROM ir_track
             WHERE IR_date BETWEEN ? AND ?";
    $st = $db->prepare($sql);
    $st->execute([$from, $to]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $qty   = (int)$r['Qty'];
        $price = (float)($r['Unit_price'] ?? 0);
        // ir_track 沒有客戶編號欄位（見 client_quarter_lib.php 說明），只能傳名稱
        $c = $resolve('', (string)$r['Client_name']);
        $out[] = [
            'id'    => (int)$r['IR_id'],
            'no'    => (string)$r['IR_no'],
            'dt'    => substr((string)$r['IR_date'], 0, 10),
            'ckey'  => $c['key'], 'cname' => $c['name'], 'cid' => (string)$c['cid'], 'cbad' => $c['unmatched'] ? 1 : 0,
            'pid'   => (int)($r['d_setting_id'] ?? 0),
            'pno'   => trim((string)$r['d_id']) ?: '（未填料號）',
            'spec'  => (string)($r['Specification'] ?? ''),
            'qty'   => $qty, 'price' => $price, 'amount' => $qty * $price,
            'reason'=> trim((string)($r['IR_ps'] ?? '')),
        ];
    }
    return $out;
}

/* ══════════════════════════════════════════════════════════════════
 * 期間彙總的空白格與加總（出貨／退貨／訂單三合一）
 * ══════════════════════════════════════════════════════════════════ */
function si_blank(): array
{
    return [
        'ship_rows' => 0, 'ship_qty' => 0, 'ship_amount' => 0.0,
        'ret_rows'  => 0, 'ret_qty'  => 0, 'ret_amount'  => 0.0,
        'ord_rows'  => 0, 'ord_qty'  => 0, 'ord_amount'  => 0.0, 'ord_px' => 0,
        'net_amount' => 0.0,
        'anomaly' => 0, 'anomaly_unconfirmed' => 0,
    ];
}
function si_add_ship(array &$a, array $r): void
{
    $a['ship_rows']++; $a['ship_qty'] += $r['qty']; $a['ship_amount'] += $r['amount'];
    if ($r['anomaly']) { $a['anomaly']++; if (!$r['anomaly_confirmed']) $a['anomaly_unconfirmed']++; }
}
function si_add_ret(array &$a, array $r): void
{
    $a['ret_rows']++; $a['ret_qty'] += $r['qty']; $a['ret_amount'] += $r['amount'];
}
function si_add_ord(array &$a, array $r): void
{
    $a['ord_rows']++; $a['ord_qty'] += $r['qty']; $a['ord_amount'] += $r['amount'];
    if ($r['haspx']) $a['ord_px']++;
}
function si_finalize(array &$a): void { $a['net_amount'] = $a['ship_amount'] - $a['ret_amount']; }

/* ══════════════════════════════════════════════════════════════════
 * 主分析：一次把畫面要的全部算完（KPI、趨勢、出貨性質分布、客戶比較、
 * 增減排名、異常摘要），不落庫、不快照——出貨隨時在改，存起來的數字
 * 只會停在存檔那天。
 * ══════════════════════════════════════════════════════════════════ */
function si_report(PDO $db, array $opt = []): array
{
    $t0    = microtime(true);
    $year  = max(2000, min(2100, (int)($opt['year'] ?? date('Y'))));
    $gran  = isset(oa_grans()[$opt['gran'] ?? '']) ? (string)$opt['gran'] : 'quarter';
    $idx   = max(1, (int)($opt['idx'] ?? 1));
    $cmpK  = isset(oa_compares()[$opt['cmp'] ?? '']) ? (string)$opt['cmp'] : 'yoy';
    $align = !array_key_exists('align', $opt) || !empty($opt['align']);
    $topN  = max(5, min(200, (int)($opt['top'] ?? 20)));
    $sel   = array_values(array_filter(array_map('strval', (array)($opt['clients'] ?? []))));
    $selMap = $sel ? array_flip($sel) : [];
    $saleTypes = si_sale_types_whitelist($db, $opt['sale_types'] ?? []);

    $cur  = oa_period_pick($year, $gran, $idx);
    $cmpP = oa_compare_period($year, $gran, $idx, $cmpK);
    $elap = oa_elapsed_days($cur);
    $curE = $cur;
    $cmpE = $align ? oa_cap_period($cmpP, $elap) : $cmpP;

    $from = min(($year - 1) . '-01-01', $cmpP['start']);
    $to   = max($year . '-12-31', $cur['end']);

    $resolve = cqa_client_resolver($db);
    $shipRows = si_fetch_ship($db, $from, $to, ['sale_types' => $saleTypes, 'resolver' => $resolve]);
    $retRows  = si_fetch_return($db, $from, $to, ['resolver' => $resolve]);
    $ordRows  = oa_fetch_orders($db, $from, $to, ['basis' => 'order', 'include_paused' => false]);
    $clFirst  = oa_client_first_seen($db);

    $inSel   = function (array $r) use ($selMap) { return !$selMap || isset($selMap[$r['ckey']]); };
    $inRange = function (array $r, array $p) { return $r['dt'] >= $p['start'] && $r['dt'] <= $p['end']; };

    /* ── KPI：本期 vs 比較期 ─────────────────────────── */
    $mkAgg = function (array $p) use ($shipRows, $retRows, $ordRows, $inSel, $inRange) {
        $a = si_blank(); $cs = [];
        foreach ($shipRows as $r) { if ($inSel($r) && $inRange($r, $p)) { si_add_ship($a, $r); $cs[$r['ckey']] = 1; } }
        foreach ($retRows  as $r) { if ($inSel($r) && $inRange($r, $p)) { si_add_ret($a, $r);  $cs[$r['ckey']] = 1; } }
        foreach ($ordRows  as $r) { if ($inSel($r) && $inRange($r, $p)) { si_add_ord($a, $r);  $cs[$r['ckey']] = 1; } }
        si_finalize($a);
        $a['clients'] = count($cs);
        return $a;
    };
    $kpiCur = $mkAgg($curE);
    $kpiCmp = $mkAgg($cmpE);

    /* ── 趨勢：本年度全部期別 ＋ 去年同粒度 ─────────────── */
    $mkSeries = function (int $y) use ($gran, $shipRows, $retRows, $ordRows, $inSel, $inRange) {
        $out = [];
        foreach (oa_period_buckets($y, $gran) as $b) {
            $a = si_blank();
            foreach ($shipRows as $r) { if ($inSel($r) && $inRange($r, $b)) si_add_ship($a, $r); }
            foreach ($retRows  as $r) { if ($inSel($r) && $inRange($r, $b)) si_add_ret($a, $r); }
            foreach ($ordRows  as $r) { if ($inSel($r) && $inRange($r, $b)) si_add_ord($a, $r); }
            si_finalize($a);
            $a['idx'] = $b['idx']; $a['label'] = $b['label']; $a['start'] = $b['start']; $a['end'] = $b['end'];
            $out[] = $a;
        }
        return $out;
    };
    $trendCur  = $mkSeries($year);
    $trendPrev = $mkSeries($year - 1);

    /* ── 出貨性質分布（本期，依出貨金額）────────────────── */
    $stAgg = [];
    foreach ($shipRows as $r) {
        if (!$inSel($r) || !$inRange($r, $curE)) continue;
        $k = $r['st_name'];
        if (!isset($stAgg[$k])) $stAgg[$k] = ['name' => $k, 'rows' => 0, 'qty' => 0, 'amount' => 0.0];
        $stAgg[$k]['rows']++; $stAgg[$k]['qty'] += $r['qty']; $stAgg[$k]['amount'] += $r['amount'];
    }
    $stAgg = array_values($stAgg);
    usort($stAgg, function ($a, $b) { return $b['amount'] <=> $a['amount']; });

    /* ── 客戶比較：選了客戶就比那幾家，沒選就自動取本期淨額前 8 名 ── */
    $byClient = [];
    $accum = function (array $p, string $slot, string $kind) use ($shipRows, $retRows, $ordRows, &$byClient, $inSel, $inRange) {
        $rows = $kind === 'ship' ? $shipRows : ($kind === 'ret' ? $retRows : $ordRows);
        foreach ($rows as $r) {
            if (!$inSel($r) || !$inRange($r, $p)) continue;
            $k = $r['ckey'];
            if (!isset($byClient[$k])) $byClient[$k] = ['key' => $k, 'name' => $r['cname'], 'cid' => $r['cid'],
                                                        'bad' => $r['cbad'], 'cur' => si_blank(), 'cmp' => si_blank()];
            if ($kind === 'ship') si_add_ship($byClient[$k][$slot], $r);
            elseif ($kind === 'ret') si_add_ret($byClient[$k][$slot], $r);
            else si_add_ord($byClient[$k][$slot], $r);
        }
    };
    foreach (['ship', 'ret', 'ord'] as $kind) { $accum($curE, 'cur', $kind); $accum($cmpE, 'cmp', $kind); }
    $clientRows = [];
    foreach ($byClient as $k => $c) {
        si_finalize($c['cur']); si_finalize($c['cmp']);
        $c['d_net']    = $c['cur']['net_amount']  - $c['cmp']['net_amount'];
        $c['d_ship']   = $c['cur']['ship_amount'] - $c['cmp']['ship_amount'];
        $c['d_order']  = $c['cur']['ord_amount']  - $c['cmp']['ord_amount'];
        $hadCur = ($c['cur']['ship_rows'] + $c['cur']['ret_rows'] + $c['cur']['ord_rows']) > 0;
        $hadCmp = ($c['cmp']['ship_rows'] + $c['cmp']['ret_rows'] + $c['cmp']['ord_rows']) > 0;
        $cf = $clFirst[$k] ?? null;
        $c['first'] = $cf ? $cf['d'] : ''; $c['fsrc'] = $cf ? $cf['s'] : '';
        if (!$hadCmp && $hadCur) {
            $c['flag'] = ($cf && $cf['d'] >= $curE['start'] && $cf['d'] <= $curE['end']) ? 'new' : 'return';
        } elseif ($hadCmp && !$hadCur) {
            $c['flag'] = 'lost';
        } else {
            $c['flag'] = '';
        }
        $clientRows[] = $c;
    }

    $cmpKeys = $sel;
    if (!$cmpKeys) {
        $tmp = $clientRows;
        usort($tmp, function ($a, $b) { return $b['cur']['net_amount'] <=> $a['cur']['net_amount']; });
        foreach (array_slice($tmp, 0, 8) as $c) $cmpKeys[] = $c['key'];
    }
    $cmpSeries = [];
    foreach ($cmpKeys as $k) {
        if (!isset($byClient[$k])) continue;
        $row = ['key' => $k, 'name' => $byClient[$k]['name'], 'ship' => [], 'ret' => [], 'ord' => [], 'net' => []];
        foreach (oa_period_buckets($year, $gran) as $b) {
            $a = si_blank();
            foreach ($shipRows as $r) { if ($r['ckey'] === $k && $inRange($r, $b)) si_add_ship($a, $r); }
            foreach ($retRows  as $r) { if ($r['ckey'] === $k && $inRange($r, $b)) si_add_ret($a, $r); }
            foreach ($ordRows  as $r) { if ($r['ckey'] === $k && $inRange($r, $b)) si_add_ord($a, $r); }
            si_finalize($a);
            $row['ship'][] = round($a['ship_amount']); $row['ret'][] = round($a['ret_amount']);
            $row['ord'][]  = round($a['ord_amount']);  $row['net'][] = round($a['net_amount']);
        }
        $cmpSeries[] = $row;
    }

    /* ── 客戶增減排名：依「增減淨額」排序，不是依百分比 ─────── */
    $rankMetricKey = 'd_net';
    $rankClients = $clientRows;
    usort($rankClients, function ($a, $b) use ($rankMetricKey) { return $b[$rankMetricKey] <=> $a[$rankMetricKey]; });

    /* ── 異常摘要（本期，含未確認清單前 200 筆）───────────── */
    $anomList = [];
    foreach ($shipRows as $r) {
        if (!$inSel($r) || !$inRange($r, $curE) || !$r['anomaly'] || $r['anomaly_confirmed']) continue;
        $anomList[] = $r;
        if (count($anomList) >= 200) break;
    }
    usort($anomList, function ($a, $b) { return strcmp($b['dt'], $a['dt']); });

    /* ── 未歸戶提醒 ─────────────────────────────────────── */
    $warnNoClient = 0; $warnNoPart = 0;
    foreach ($shipRows as $r) { if ($inSel($r) && $inRange($r, $curE)) { if ($r['cbad']) $warnNoClient++; if (!$r['pid']) $warnNoPart++; } }

    $covCur = $kpiCur['ord_rows'] ? round($kpiCur['ord_px'] * 100 / $kpiCur['ord_rows'], 1) : 0.0;
    $covCmp = $kpiCmp['ord_rows'] ? round($kpiCmp['ord_px'] * 100 / $kpiCmp['ord_rows'], 1) : 0.0;

    return [
        'meta' => [
            'year' => $year, 'gran' => $gran, 'gran_label' => oa_grans()[$gran], 'idx' => $idx,
            'cmp' => $cmpK, 'cmp_label' => oa_compares()[$cmpK],
            'period' => $cur, 'period_eff' => $curE, 'cmp_period' => $cmpP, 'cmp_period_eff' => $cmpE,
            'elapsed_days' => $elap, 'align' => $align ? 1 : 0,
            'buckets' => array_map(function ($b) { return $b['label']; }, oa_period_buckets($year, $gran)),
            'sale_types_selected' => $saleTypes,
            'clients_selected' => $sel,
            'ord_px_cov_cur' => $covCur, 'ord_px_cov_cmp' => $covCmp,
            'warn' => ['no_client' => $warnNoClient, 'no_part' => $warnNoPart],
            'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
            'today' => date('Y-m-d'),
        ],
        'kpi'        => ['cur' => $kpiCur, 'cmp' => $kpiCmp],
        'trend'      => ['cur' => $trendCur, 'prev' => $trendPrev, 'prev_year' => $year - 1],
        'sale_type_stat' => $stAgg,
        'clients'    => $clientRows,
        'client_cmp' => ['keys' => $cmpKeys, 'series' => $cmpSeries],
        'rank_clients' => $rankClients,
        'anomaly_list' => array_slice($anomList, 0, 50),
        'anomaly_total_unconfirmed' => count($anomList),
    ];
}

/* ══════════════════════════════════════════════════════════════════
 * 逐月出貨淨額（出貨－退貨）＋移動平均監控（沿用 order_analysis_lib 的做法，
 * 換成出貨金額；KPI 安全水平來源改用「月銷貨額達成率」calculator_key='shipping_target_amount'）
 * ══════════════════════════════════════════════════════════════════ */
function si_month_amounts(PDO $db, string $fromYm, string $toYm): array
{
    $from = $fromYm . '-01';
    $to   = date('Y-m-t', strtotime($toYm . '-01'));
    $saleCond = cqa_sale_type_sql(null);
    $sql = "SELECT DATE_FORMAT(isl.Order_date,'%Y-%m') ym, COUNT(*) rows_n, SUM(isl.Qty) qty,
                   SUM(isl.Qty*isl.Unit_price) amount
              FROM is_list isl
              LEFT JOIN is_sale_type ist ON isl.sale_type = ist.sale_type_id
             WHERE isl.Order_date BETWEEN ? AND ? AND $saleCond
             GROUP BY ym";
    $st = $db->prepare($sql);
    $st->execute([$from, $to]);
    $ship = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $ship[(string)$r['ym']] = (float)$r['amount'];

    $st2 = $db->prepare("SELECT DATE_FORMAT(IR_date,'%Y-%m') ym, SUM(Qty*COALESCE(Unit_price,0)) amount
                           FROM ir_track WHERE IR_date BETWEEN ? AND ? GROUP BY ym");
    $st2->execute([$from, $to]);
    $ret = [];
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) $ret[(string)$r['ym']] = (float)$r['amount'];

    $out = []; $cur = $fromYm;
    while ($cur <= $toYm) {
        $s = $ship[$cur] ?? 0.0; $r = $ret[$cur] ?? 0.0;
        $out[$cur] = ['ym' => $cur, 'ship' => $s, 'ret' => $r, 'amount' => $s - $r];
        $cur = date('Y-m', strtotime($cur . '-01 +1 month'));
    }
    ksort($out);
    return $out;
}

function si_settings_default(): array
{
    return [
        'kpi_indicator_id'   => 0,          // 0＝自動找 shipping_target_amount
        'kpi_alert_months'   => 3,
        'ma_enabled'         => 0,
        'ma_months'          => 3,
        'ma_consecutive'     => 2,
        'ma_threshold_mode'  => 'kpi',      // kpi＝該年度月銷貨目標金額／manual＝自訂
        'ma_threshold_value' => 0,
        'ma_notify_users'    => [],
        'anomaly_alert_min'  => 5,          // 未確認異常筆數達到這個門檻才在自動分析標成「要處理」
    ];
}
function si_settings_clamp(array $out): array
{
    $d = si_settings_default();
    foreach ($d as $k => $v) if (!array_key_exists($k, $out)) $out[$k] = $v;
    $out['kpi_alert_months']   = max(1, min(12, (int)$out['kpi_alert_months']));
    $out['ma_months']          = max(2, min(12, (int)$out['ma_months']));
    $out['ma_consecutive']     = max(1, min(6,  (int)$out['ma_consecutive']));
    $out['ma_threshold_value'] = max(0, (int)$out['ma_threshold_value']);
    $out['ma_enabled']         = !empty($out['ma_enabled']) ? 1 : 0;
    $out['kpi_indicator_id']   = max(0, (int)$out['kpi_indicator_id']);
    $out['anomaly_alert_min']  = max(1, min(500, (int)$out['anomaly_alert_min']));
    if (!in_array($out['ma_threshold_mode'], ['kpi', 'manual'], true)) $out['ma_threshold_mode'] = 'kpi';
    $out['ma_notify_users'] = array_values(array_unique(array_map('intval', (array)$out['ma_notify_users'])));
    return $out;
}
function si_settings(PDO $db): array
{
    $d = si_settings_default();
    $s = si_param_get($db, 'alert_settings', null);
    if (!is_array($s)) return $d;
    $out = $d;
    foreach ($d as $k => $v) {
        if (!array_key_exists($k, $s)) continue;
        if (is_array($v)) $out[$k] = is_array($s[$k]) ? array_values(array_map('intval', $s[$k])) : [];
        elseif (is_int($v)) $out[$k] = (int)$s[$k];
        else $out[$k] = (string)$s[$k];
    }
    return si_settings_clamp($out);
}
function si_settings_save(PDO $db, array $in, string $by): array
{
    $cur = si_settings($db);
    foreach (si_settings_default() as $k => $v) {
        if (!array_key_exists($k, $in)) continue;
        if (is_array($v))   $cur[$k] = array_values(array_unique(array_map('intval', (array)$in[$k])));
        elseif (is_int($v)) $cur[$k] = (int)$in[$k];
        else                $cur[$k] = (string)$in[$k];
    }
    $cur = si_settings_clamp($cur);
    $err = [];
    if ($cur['ma_threshold_mode'] === 'manual' && (int)$cur['ma_threshold_value'] <= 0) $err[] = '選「自訂金額」時，安全水平金額必須大於 0';
    if (!empty($cur['ma_enabled']) && !$cur['ma_notify_users']) $err[] = '啟用移動平均監控時，一定要指定至少一位收通知的人員';
    if ($err) return ['ok' => false, 'errors' => $err];
    si_param_save($db, 'alert_settings', $cur, $by);
    return ['ok' => true, 'settings' => $cur];
}

/** 出貨 KPI（月銷貨額達成率）的指標與該年度設定 */
function si_kpi_iy(PDO $db, int $year): ?array
{
    $sid = (int)si_settings($db)['kpi_indicator_id'];
    try {
        if ($sid > 0) {
            $st = $db->prepare("SELECT iy.*, i.name, i.value_type FROM kpi_as_indicator_year iy
                                JOIN kpi_as_indicator i ON i.indicator_id=iy.indicator_id
                                WHERE iy.indicator_id=? AND iy.year=? LIMIT 1");
            $st->execute([$sid, $year]);
        } else {
            $st = $db->prepare("SELECT iy.*, i.name, i.value_type FROM kpi_as_indicator_year iy
                                JOIN kpi_as_indicator i ON i.indicator_id=iy.indicator_id
                                WHERE iy.calculator_key='shipping_target_amount' AND iy.year=? LIMIT 1");
            $st->execute([$year]);
        }
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}
function si_kpi_monthly_targets(?array $iy): array { return oa_kpi_monthly_targets($iy); }

/** 移動平均監控（出貨淨額），結構比照 order_analysis_lib 的 oa_moving_avg() */
function si_moving_avg(PDO $db, array $opt = []): array
{
    $s     = si_settings($db);
    $n     = (int)($opt['months'] ?? $s['ma_months']);
    $need  = (int)($opt['consecutive'] ?? $s['ma_consecutive']);
    $endYm = (string)($opt['end_ym'] ?? date('Y-m', strtotime('first day of last month')));
    $show  = max($need + 1, (int)($opt['show'] ?? 12));

    $startYm = date('Y-m', strtotime($endYm . '-01 -' . ($show + $n) . ' month'));
    $mon = si_month_amounts($db, $startYm, $endYm);
    $keys = array_keys($mon);

    $thrOf = function (string $ym) use ($db, $s) {
        if ($s['ma_threshold_mode'] === 'manual') return (float)$s['ma_threshold_value'];
        static $cache = [];
        $y = (int)substr($ym, 0, 4); $m = (int)substr($ym, 5, 2);
        if (!array_key_exists($y, $cache)) $cache[$y] = si_kpi_monthly_targets(si_kpi_iy($db, $y));
        return $cache[$y][$m] ?? null;
    };

    $series = [];
    foreach ($keys as $i => $ym) {
        if ($i < $n - 1) continue;
        $win = array_slice($keys, $i - $n + 1, $n);
        $sum = 0.0;
        foreach ($win as $w) $sum += $mon[$w]['amount'];
        $avg = $sum / $n;
        $thr = $thrOf($ym);
        $series[] = ['ym' => $ym, 'avg' => $avg, 'amount' => $mon[$ym]['amount'], 'ship' => $mon[$ym]['ship'],
                     'ret' => $mon[$ym]['ret'], 'threshold' => $thr, 'window' => $win,
                     'below' => ($thr !== null && $avg < $thr) ? 1 : 0];
    }
    $series = array_slice($series, -$show);

    $streak = 0;
    for ($i = count($series) - 1; $i >= 0; $i--) { if (empty($series[$i]['below'])) break; $streak++; }
    return ['enabled' => (int)$s['ma_enabled'], 'months' => $n, 'need' => $need,
            'mode' => $s['ma_threshold_mode'], 'manual_value' => (float)$s['ma_threshold_value'],
            'series' => $series, 'streak' => $streak, 'hit' => ($streak >= $need) ? 1 : 0,
            'end_ym' => $endYm, 'notify_users' => $s['ma_notify_users']];
}

/** 出貨 KPI（月銷貨額達成率）未達標提醒，結構比照 oa_kpi_alert() */
function si_kpi_alert(PDO $db, ?string $today = null): ?array
{
    require_once __DIR__ . '/kpi_as_lib.php';
    $s = si_settings($db);
    $n = (int)$s['kpi_alert_months'];
    $today = $today ?: date('Y-m-d');
    $y = (int)date('Y', strtotime($today)); $m = (int)date('n', strtotime($today));

    $rows = []; $iyCache = [];
    for ($i = 1; $i <= $n; $i++) {
        $mm = $m - $i; $yy = $y;
        while ($mm <= 0) { $mm += 12; $yy--; }
        if (!isset($iyCache[$yy])) $iyCache[$yy] = si_kpi_iy($db, $yy);
        $iy = $iyCache[$yy];
        if (!$iy) { $rows[] = ['year' => $yy, 'month' => $mm, 'has' => 0]; continue; }
        $mv = null;
        try {
            $st = $db->prepare("SELECT * FROM kpi_as_monthly_value WHERE indicator_id=? AND year=? AND month=? LIMIT 1");
            $st->execute([(int)$iy['indicator_id'], $yy, $mm]);
            $mv = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {}
        $val = kpi_as_display_value($mv);
        $below = kpi_as_below_target($val, $iy);
        $rows[] = ['year' => $yy, 'month' => $mm, 'has' => $val === null ? 0 : 1, 'value' => $val,
                   'below' => $below ? 1 : 0, 'indicator' => (string)$iy['name']];
    }
    $rows = array_reverse($rows);
    $have = array_values(array_filter($rows, function ($r) { return !empty($r['has']); }));
    if (!$have) return ['enabled' => 1, 'no_data' => 1, 'months' => $rows, 'n' => $n];
    $bad = array_values(array_filter($have, function ($r) { return !empty($r['below']); }));
    if (!$bad) return ['enabled' => 1, 'ok' => 1, 'months' => $rows, 'n' => $n];

    $iy = si_kpi_iy($db, $y);
    $tg = si_kpi_monthly_targets($iy);
    $mt = $tg[$m] ?? null;
    $cur = si_month_amounts($db, sprintf('%04d-%02d', $y, $m), sprintf('%04d-%02d', $y, $m));
    $k = sprintf('%04d-%02d', $y, $m);
    $got = $cur[$k]['ship'] ?? 0.0;
    $days = (int)date('t', strtotime($today));
    $left = max(0, $days - (int)date('j', strtotime($today)) + 1);
    return [
        'enabled' => 1, 'below' => 1, 'n' => $n, 'months' => $rows, 'bad_count' => count($bad),
        'bad_list' => array_map(function ($r) { return $r['year'] . '/' . $r['month'] . '月'; }, $bad),
        'this_year' => $y, 'this_month' => $m, 'month_target' => $mt, 'month_got' => $got,
        'month_gap' => ($mt === null) ? null : max(0, $mt - $got), 'days_left' => $left,
        'indicator' => $have[0]['indicator'] ?? '月銷貨額達成率',
    ];
}

/* ══════════════════════════════════════════════════════════════════
 * 自動分析（每一條都附數字；level：bad/warn/good/info）
 * ══════════════════════════════════════════════════════════════════ */
function si_insights(PDO $db, array $res, ?array $kpiAlert = null, ?array $ma = null): array
{
    $out = [];
    $add = function ($level, $title, $detail, $metric = '', $extra = []) use (&$out) {
        $out[] = array_merge(['level' => $level, 'title' => $title, 'detail' => $detail, 'metric' => $metric], $extra);
    };
    $m = $res['meta']; $cur = $res['kpi']['cur']; $cmp = $res['kpi']['cmp']; $cl = $m['cmp_label'];
    $fmt = function ($v) { return number_format((float)$v); };
    $rate = function ($a, $b) { $b = (float)$b; if (!$b) return null; return round((($a - $b) / abs($b)) * 100, 1); };

    /* ① 整體出貨與淨額走勢 */
    $sd = $rate($cur['ship_amount'], $cmp['ship_amount']);
    if ($sd !== null && $sd <= -10) {
        $add('bad', '出貨金額衰退', '本期出貨 ' . $fmt($cur['ship_amount']) . ' 元，較' . $cl . '的 ' . $fmt($cmp['ship_amount'])
             . ' 元減少 ' . $fmt($cmp['ship_amount'] - $cur['ship_amount']) . ' 元。', $sd . '%');
    } elseif ($sd !== null && $sd >= 10) {
        $add('good', '出貨金額成長', '本期出貨 ' . $fmt($cur['ship_amount']) . ' 元，較' . $cl . '增加 '
             . $fmt($cur['ship_amount'] - $cmp['ship_amount']) . ' 元。', '+' . $sd . '%');
    }
    $nd = $rate($cur['net_amount'], $cmp['net_amount']);
    if ($nd !== null && $nd <= -10) {
        $add('bad', '淨額（出貨－退貨）衰退', '本期淨額 ' . $fmt($cur['net_amount']) . ' 元，較' . $cl . '的 '
             . $fmt($cmp['net_amount']) . ' 元減少 ' . $fmt($cmp['net_amount'] - $cur['net_amount']) . ' 元。', $nd . '%');
    }

    /* ② 連續兩期下滑（單期比較看不出來的趨勢型警訊） */
    $tr = $res['trend']['cur'];
    $idx = -1;
    foreach ($tr as $i => $b) if ((int)$b['idx'] === (int)$m['idx']) { $idx = $i; break; }
    if ($idx >= 2) {
        $a0 = (float)$tr[$idx]['net_amount']; $a1 = (float)$tr[$idx - 1]['net_amount']; $a2 = (float)$tr[$idx - 2]['net_amount'];
        if ($a0 < $a1 && $a1 < $a2 && $a2 > 0) {
            $add('bad', '淨額連續兩期下滑', '「' . $tr[$idx - 2]['label'] . '」→「' . $tr[$idx - 1]['label'] . '」→「'
                 . $tr[$idx]['label'] . '」的出貨淨額一路往下（' . $fmt($a2) . ' → ' . $fmt($a1) . ' → ' . $fmt($a0)
                 . '），這種趨勢單看一期比較是看不出來的。', round(($a0 - $a2) / $a2 * 100, 1) . '%');
        }
    }

    /* ③ 客戶集中度 */
    $tot = 0.0; $vals = [];
    foreach ($res['clients'] as $c) { $v = (float)$c['cur']['ship_amount']; if ($v > 0) { $vals[] = ['n' => $c['name'], 'v' => $v]; $tot += $v; } }
    usort($vals, function ($a, $b) { return $b['v'] <=> $a['v']; });
    if ($tot > 0 && count($vals) >= 3) {
        $t3 = $vals[0]['v'] + $vals[1]['v'] + $vals[2]['v'];
        $p3 = round($t3 * 100 / $tot, 1);
        if ($p3 >= 50) {
            $add('warn', '客戶集中度偏高', '前三大客戶（' . $vals[0]['n'] . '、' . $vals[1]['n'] . '、' . $vals[2]['n']
                 . '）就佔了本期出貨金額 ' . $p3 . '%，其中任何一家減單都會直接反映在總量上。', $p3 . '%');
        }
    }

    /* ④ 流失客戶／新客戶／回流客戶 */
    $lost = array_values(array_filter($res['clients'], function ($c) { return $c['flag'] === 'lost'; }));
    if ($lost) {
        $sample = array_slice($lost, 0, 30);
        $add('warn', '流失客戶：' . count($lost) . ' 家', $cl . '有出貨／訂單，本期完全沒有——'
             . implode('、', array_map(function ($c) { return $c['name']; }, array_slice($lost, 0, 5)))
             . (count($lost) > 5 ? ' 等' : '') . '。', count($lost) . ' 家',
             ['clients' => array_map(function ($c) {
                 return ['name' => $c['name'], 'cid' => $c['cid'], 'bad' => $c['bad'], 'amount' => round($c['cmp']['ship_amount'])];
             }, $sample), 'unit' => '元', 'cmp_label' => $cl]);
    }
    $newC = array_values(array_filter($res['clients'], function ($c) { return $c['flag'] === 'new'; }));
    $retC = array_values(array_filter($res['clients'], function ($c) { return $c['flag'] === 'return'; }));
    if ($newC) $add('good', '新客戶：' . count($newC) . ' 家', '系統裡本期才第一次出現的客戶——'
        . implode('、', array_map(function ($c) { return $c['name']; }, array_slice($newC, 0, 5)))
        . (count($newC) > 5 ? ' 等' : '') . '。', count($newC) . ' 家');
    if ($retC) $add('info', '回流客戶：' . count($retC) . ' 家', '以前就有往來、只是' . $cl . '剛好沒下單，這期又回來——'
        . implode('、', array_map(function ($c) { return $c['name']; }, array_slice($retC, 0, 5)))
        . (count($retC) > 5 ? ' 等' : '') . '（不是新開發的客源，要問的是「之前為什麼停了」）。', count($retC) . ' 家');

    /* ⑤ 退貨異常增加 */
    $retRate = $cur['ship_amount'] > 0 ? round($cur['ret_amount'] * 100 / $cur['ship_amount'], 1) : 0;
    $retRateCmp = $cmp['ship_amount'] > 0 ? round($cmp['ret_amount'] * 100 / $cmp['ship_amount'], 1) : 0;
    if ($retRate >= 5 && $retRate > $retRateCmp) {
        $add('warn', '退貨率偏高', '本期退貨金額佔出貨金額 ' . $retRate . '%（' . $cl . '為 ' . $retRateCmp . '%），'
             . '退貨金額 ' . $fmt($cur['ret_amount']) . ' 元。', $retRate . '%');
    }

    /* ⑥ 未確認異常出貨 */
    $s = si_settings($db);
    if ($cur['anomaly_unconfirmed'] >= (int)$s['anomaly_alert_min']) {
        $add('bad', '有未確認的異常出貨', '本期有 ' . $cur['anomaly_unconfirmed'] . ' 筆出貨被判定異常（多半是漏填單價，或出貨性質設定為'
             . '「非零視為異常」卻登記了金額）尚未確認，請到「異常偵測」逐筆確認或修正。', $cur['anomaly_unconfirmed'] . ' 筆');
    } elseif ($cur['anomaly_unconfirmed'] > 0) {
        $add('info', '有少量未確認的異常出貨', '本期 ' . $cur['anomaly_unconfirmed'] . ' 筆，可到「異常偵測」查看。', $cur['anomaly_unconfirmed'] . ' 筆');
    }

    /* ⑦ KPI（月銷貨額達成率）未達標 */
    if ($kpiAlert && !empty($kpiAlert['below'])) {
        $gap = $kpiAlert['month_gap'];
        $add('bad', '月銷貨額連續未達標', '最近 ' . $kpiAlert['n'] . ' 個月有 ' . $kpiAlert['bad_count'] . ' 個月「'
             . $kpiAlert['indicator'] . '」未達標（' . implode('、', $kpiAlert['bad_list']) . '）'
             . ($gap === null ? '' : ('，本月還差 ' . $fmt($gap) . ' 元、剩 ' . $kpiAlert['days_left'] . ' 天。')),
             $kpiAlert['bad_count'] . ' 個月');
    }

    /* ⑧ 移動平均監控 */
    if ($ma && !empty($ma['hit'])) {
        $add('bad', '出貨淨額移動平均連續低於安全水平', '已連續 ' . $ma['streak'] . ' 個月低於安全水平（前 ' . $ma['months'] . ' 月移動平均）。',
             $ma['streak'] . ' 個月');
    }

    /* ⑨ 未歸戶提醒 */
    if (!empty($m['warn']['no_client'])) {
        $add('info', '有出貨對不到客戶主檔', '本期 ' . $m['warn']['no_client'] . ' 筆出貨的客戶名稱對不到客戶主檔（已自成一組並標「未建主檔」）。',
             $m['warn']['no_client'] . ' 筆');
    }

    if (!$out) $add('info', '本期沒有需要特別指出的變化', '各項指標與' . $cl . '相比沒有明顯異常波動。');
    return $out;
}
