<?php
/**
 * src/common/client_quarter_lib.php — 客戶季度分析（訂單／出貨／退貨）唯一實作
 *
 * 用途：把同一個客戶的「訂單／出貨／退貨」攤成逐季序列，供
 *   ① 單一客戶（或全部客戶）的季趨勢圖與明細
 *   ② 以季為單位自動判斷「哪些客戶成長、哪些衰退」
 * 使用端：views/Sales/Shipping_Analysis_new.php（客戶季度分析面板）
 *
 * ── 為什麼要收斂成一支共用庫 ──
 * 客戶歸戶與季歸屬這兩件事只要在兩個地方各寫一次，遲早算出兩種數字而且看不出誰對。
 * 尤其客戶歸戶：這套系統裡「同一家客戶」在三張表各有各的寫法（見下），
 * 隨手用 Client_name 分組就會把「高鋒工業」與「高鋒」算成兩家。
 *
 * ── 客戶歸戶（依序，第一個成立的就採用）──
 *   ① 該列自己綁定的客戶主檔 id
 *        出貨 is_list.Client_id     ← 實測全表 0 筆有值（ERP 匯入從來沒帶），
 *                                     仍照讀：對帳頁的「對應到客戶主檔」會回填，回填後自動生效
 *        退貨 ir_track 無此欄位
 *        訂單 order_track.Client_name_ID
 *   ② 料號主檔綁的客戶：d_setting.Customer_Id（料號本來就綁定客戶）
 *        實測 d_setting 只有 18% 有填 Customer_Id，所以這關常常過不了
 *   ③ 客戶名稱文字 → acc_customer_by_name()（**含別名**，全站唯一歸戶入口）
 *        ERP 寫「高鋒工業」、主檔是「高鋒」，只比完全相同的字串會對不到
 *   ④ 以上都不成立 → 以原始名稱自成一組，並標記 unmatched=1（畫面要標「未建主檔」）
 *
 * ── 季歸屬 ──
 *   預設沿用本站的「帳款月份」口徑（system_settings.billing_cutoff_day，目前 25）：
 *   日期 > 截止日就歸到下一個月，再換算成季。這樣季分析的數字才會跟同一頁的
 *   帳款月卡片、客戶統計對得起來。要純日曆季（對外報表）可傳 q_basis='calendar'。
 *
 * ── 進行中的季 ──
 *   拿「還沒過完的當季」去跟完整的去年同季比，整批客戶都會看起來在衰退。
 *   所以本庫支援 cap_days：每一季都只計算「從該季第 1 天起的前 N 天」，
 *   當季過了幾天就兩邊都只比到第幾天（cqa_growth 的 align 參數預設開）。
 *
 * ── 金額口徑（與 Shipping_Analysis_new.php 既有統計一致）──
 *   出貨＝Qty×Unit_price，排除 is_sale_type.is_count=0 的出貨性質（樣品等不列入統計）
 *   退貨＝Qty×Unit_price（ir_track）
 *   訂單＝Qty×unit_price，排除已取消（Order_status=9）與未開價（unit_price 為 0/NULL）
 *        ※ 沿用本頁訂單分頁的口徑「只計有單價的訂單」。實測 2024 年 2,916 張訂單
 *          只有 9 張有單價、2025 年 3,410 張只有 10 張，2026 年才開始正常填，
 *          所以一定要一併回傳 order_quality 讓畫面講清楚「是資料沒有，不是沒接單」。
 *   淨額＝出貨－退貨
 */

require_once __DIR__ . '/acc_lib.php';   // acc_customer_by_name()：客戶歸戶（含別名）全站唯一入口

/** 允許的參數值（前端送什麼都要過這裡，不可直接進 SQL） */
function cqa_order_bases(): array { return ['delivery' => '交期', 'order' => '下單日']; }
function cqa_q_bases(): array     { return ['billing' => '帳款季（依截止日）', 'calendar' => '日曆季']; }
function cqa_metrics(): array     { return ['net' => '淨額（出貨－退貨）', 'ship' => '出貨金額', 'ord' => '訂單金額']; }
function cqa_compares(): array    { return ['yoy' => '去年同季', 'qoq' => '上一季']; }

/** 全域帳款月份截止日 */
function cqa_cutoff_day(PDO $db): int
{
    static $cut = null;
    if ($cut !== null) return $cut;
    $cut = 0;
    try {
        $v = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='billing_cutoff_day' LIMIT 1")->fetchColumn();
        if ($v !== false) $cut = max(0, min(31, intval($v)));
    } catch (Throwable $e) {}
    return $cut;
}

/** 季鍵：2026Q3 */
function cqa_qkey(int $y, int $q): string { return $y . 'Q' . $q; }
/** 季標籤：2026 Q3 */
function cqa_qlabel(int $y, int $q): string { return $y . ' Q' . $q; }
/** 解析季鍵 → [年, 季] */
function cqa_qparse(string $qkey): array
{
    if (preg_match('/^(\d{4})Q([1-4])$/', $qkey, $m)) return [intval($m[1]), intval($m[2])];
    return [intval(date('Y')), 1];
}
/** 前一季 */
function cqa_qprev(int $y, int $q): array { return $q === 1 ? [$y - 1, 4] : [$y, $q - 1]; }

/**
 * 某一季實際涵蓋的日期區間。
 * 帳款制：季 Q 涵蓋帳款月 3Q-2 ~ 3Q，而帳款月 M 的實際日期是
 *         「前一個月的 截止日+1」到「當月的 截止日」。
 */
function cqa_quarter_range(int $y, int $q, string $qBasis, int $cut): array
{
    $q = max(1, min(4, $q));
    $m1 = $q * 3 - 2;           // 該季第一個月
    $m3 = $q * 3;               // 該季最後一個月
    if ($qBasis !== 'billing' || $cut <= 0) {
        $start = sprintf('%04d-%02d-01', $y, $m1);
        $end   = (new DateTime(sprintf('%04d-%02d-01', $y, $m3)))->format('Y-m-t');
        return [$start, $end];
    }
    // 起：第一個月的前一個月，截止日+1
    $py = ($m1 === 1) ? $y - 1 : $y;
    $pm = ($m1 === 1) ? 12 : $m1 - 1;
    $pDays = (int)(new DateTime(sprintf('%04d-%02d-01', $py, $pm)))->format('t');
    $start = sprintf('%04d-%02d-%02d', $py, $pm, min($cut + 1, $pDays));
    // 迄：最後一個月的截止日
    $eDays = (int)(new DateTime(sprintf('%04d-%02d-01', $y, $m3)))->format('t');
    $end   = sprintf('%04d-%02d-%02d', $y, $m3, min($cut, $eDays));
    return [$start, $end];
}

/**
 * 某一季的進度（這一季過了幾天、是不是還沒過完）
 * @return array ['start','end','days_total','days_done','is_partial']
 */
function cqa_quarter_progress(PDO $db, int $y, int $q, string $qBasis, int $cut, ?string $today = null): array
{
    [$s, $e] = cqa_quarter_range($y, $q, $qBasis, $cut);
    $today = $today ?: date('Y-m-d');
    $ds = new DateTime($s); $de = new DateTime($e); $dt = new DateTime($today);
    $total = (int)$ds->diff($de)->days + 1;
    if ($dt < $ds)      $done = 0;
    elseif ($dt >= $de) $done = $total;
    else                $done = (int)$ds->diff($dt)->days + 1;
    return ['start' => $s, 'end' => $e, 'days_total' => $total, 'days_done' => $done,
            'is_partial' => ($done > 0 && $done < $total), 'is_future' => ($done === 0)];
}

/**
 * 每一季實際要計算的日期視窗。
 * $capDays 為 null＝整季；給數字＝只算該季的前 N 天（當季與比較季一起截，才比得公平）。
 */
function cqa_quarter_windows(array $quarters, string $qBasis, int $cut, ?int $capDays = null): array
{
    $out = [];
    foreach ($quarters as $qk) {
        [$y, $q] = cqa_qparse($qk);
        [$s, $e] = cqa_quarter_range($y, $q, $qBasis, $cut);
        if ($capDays !== null && $capDays > 0) {
            $cap = (new DateTime($s))->modify('+' . ($capDays - 1) . ' days')->format('Y-m-d');
            if ($cap < $e) $e = $cap;
        }
        $out[$qk] = [$s, $e];
    }
    return $out;
}

/**
 * 客戶歸戶解析器（回傳 callable，內部快取主檔與別名，一次請求只查一次）
 * 回傳 ['key'=>唯一鍵, 'name'=>顯示名稱, 'cid'=>客戶編號(字串)或'', 'unmatched'=>bool]
 *
 * ⚠ 客戶編號是 **char(11) 文字**（C2005、T2001…）不是整數，四張表都一樣
 *   （customer_list.customer_id／d_setting.Customer_Id／order_track.Client_name_ID／is_list.Client_id）。
 *   所以 SQL 裡**不可以寫 NULLIF(col, 0)**：MySQL 會把 'T2001' 轉成數字 0 來比，
 *   整欄都會被判成 NULL，歸戶就安靜地退化成只剩名稱比對（本庫第一版踩過，
 *   症狀是「有綁客戶主檔的資料照樣被當成沒綁」而且完全不報錯）。一律用 NULLIF(TRIM(col),'')。
 *   PHP 端同理，不可以 intval()。
 */
function cqa_client_resolver(PDO $db): callable
{
    $byName = acc_customer_by_name($db);          // 簡稱／別名 → 客戶主檔列（含別名）
    $byId   = [];
    try {
        foreach ($db->query("SELECT customer_id, customer FROM customer_list")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $byId[(string)$c['customer_id']] = trim((string)$c['customer']);
        }
    } catch (Throwable $e) {}

    return function ($cid, $name) use ($byId, $byName): array {
        $cid  = trim((string)$cid);
        $name = trim((string)$name);
        if ($cid !== '' && $cid !== '0' && isset($byId[$cid])) {
            return ['key' => 'C' . $cid, 'name' => $byId[$cid], 'cid' => $cid, 'unmatched' => false];
        }
        if ($name !== '' && isset($byName[$name])) {
            $c = $byName[$name];
            return ['key' => 'C' . $c['customer_id'], 'name' => trim((string)$c['customer']),
                    'cid' => trim((string)$c['customer_id']), 'unmatched' => false];
        }
        if ($name === '') $name = '（未指定客戶）';
        return ['key' => 'N:' . $name, 'name' => $name, 'cid' => 0, 'unmatched' => true];
    };
}

/** 產生「年／季」的 SQL 運算式（欄位名一律由呼叫端以常數傳入，不吃前端輸入） */
function cqa_period_expr(string $col, string $qBasis, int $cutoff): array
{
    $c = '`' . str_replace('`', '', $col) . '`';
    if ($qBasis !== 'billing' || $cutoff <= 0) {
        return ['yr' => "YEAR($c)", 'qt' => "QUARTER($c)"];
    }
    $yr = "CASE WHEN DAY($c) > $cutoff THEN IF(MONTH($c)=12, YEAR($c)+1, YEAR($c)) ELSE YEAR($c) END";
    $mo = "CASE WHEN DAY($c) > $cutoff THEN IF(MONTH($c)=12, 1, MONTH($c)+1) ELSE MONTH($c) END";
    return ['yr' => $yr, 'qt' => "FLOOR((($mo) - 1) / 3) + 1"];
}

/** 由季視窗組出「日期落在其中任一視窗」的 SQL 條件與參數 */
function cqa_window_sql(string $col, array $windows): array
{
    $c = '`' . str_replace('`', '', $col) . '`';
    $parts = []; $args = [];
    foreach ($windows as $w) {
        if ($w[1] < $w[0]) continue;               // 未來的季（還沒開始）不必查
        $parts[] = "($c BETWEEN ? AND ?)";
        $args[] = $w[0]; $args[] = $w[1];
    }
    if (!$parts) return ['0', []];                 // 一個視窗都沒有＝查不到任何資料
    return ['(' . implode(' OR ', $parts) . ')', $args];
}

/**
 * 逐客戶、逐季的訂單／出貨／退貨彙總
 *
 * @param array $opt year_from, year_to, order_basis(delivery|order), q_basis(billing|calendar),
 *                   cap_days(null=整季 / N=每季只算前 N 天)
 */
function cqa_quarter_rows(PDO $db, array $opt = []): array
{
    $yFrom = max(2000, min(2100, intval($opt['year_from'] ?? (int)date('Y'))));
    $yTo   = max($yFrom, min(2100, intval($opt['year_to']  ?? $yFrom)));
    $ob    = isset($opt['order_basis']) && isset(cqa_order_bases()[$opt['order_basis']]) ? $opt['order_basis'] : 'delivery';
    $qb    = isset($opt['q_basis'])     && isset(cqa_q_bases()[$opt['q_basis']])         ? $opt['q_basis']     : 'billing';
    $cut   = ($qb === 'billing') ? cqa_cutoff_day($db) : 0;
    $cap   = isset($opt['cap_days']) && $opt['cap_days'] !== null ? max(1, intval($opt['cap_days'])) : null;

    $quarters = [];
    for ($y = $yFrom; $y <= $yTo; $y++) for ($q = 1; $q <= 4; $q++) $quarters[] = cqa_qkey($y, $q);
    $windows = cqa_quarter_windows($quarters, $qb, $cut, $cap);

    $resolve = cqa_client_resolver($db);
    $clients = [];

    $merge = function (array $rows, string $kind) use (&$clients, $resolve) {
        foreach ($rows as $r) {
            $y = intval($r['yr']); $q = intval($r['qt']);
            if ($q < 1 || $q > 4) continue;
            $c  = $resolve($r['cid'] ?? 0, $r['cname'] ?? '');
            $k  = $c['key'];
            $qk = cqa_qkey($y, $q);
            if (!isset($clients[$k])) {
                $clients[$k] = ['key' => $k, 'name' => $c['name'], 'cid' => $c['cid'],
                                'unmatched' => $c['unmatched'], 'q' => [],
                                'total' => ['ship' => 0.0, 'ret' => 0.0, 'ord' => 0.0, 'net' => 0.0]];
            }
            if (!isset($clients[$k]['q'][$qk])) {
                $clients[$k]['q'][$qk] = ['ship' => 0.0, 'ret' => 0.0, 'ord' => 0.0, 'net' => 0.0,
                                          'ship_qty' => 0.0, 'ret_qty' => 0.0,
                                          'ship_cnt' => 0, 'ret_cnt' => 0, 'ord_cnt' => 0, 'ship_noprice' => 0];
            }
            $cell = &$clients[$k]['q'][$qk];
            $amt  = floatval($r['amt']);
            if ($kind === 'ship') {
                $cell['ship']     += $amt;  $cell['ship_qty'] += floatval($r['qty']);
                $cell['ship_cnt'] += intval($r['cnt']);
                $cell['ship_noprice'] += intval($r['noprice'] ?? 0);
                $clients[$k]['total']['ship'] += $amt;
            } elseif ($kind === 'ret') {
                $cell['ret']     += $amt;   $cell['ret_qty'] += floatval($r['qty']);
                $cell['ret_cnt'] += intval($r['cnt']);
                $clients[$k]['total']['ret'] += $amt;
            } else {
                $cell['ord']     += $amt;   $cell['ord_cnt'] += intval($r['cnt']);
                $clients[$k]['total']['ord'] += $amt;
            }
            $cell['net'] = $cell['ship'] - $cell['ret'];
            unset($cell);
        }
    };

    // ── 出貨 is_list ──
    $e = cqa_period_expr('Order_date', $qb, $cut);
    [$wSql, $wArgs] = cqa_window_sql('Order_date', $windows);
    $sql = "SELECT t.yr, t.qt, t.cid, t.cname,
                   SUM(t.amt) amt, SUM(t.qty) qty, COUNT(*) cnt, SUM(t.noprice) noprice
            FROM (SELECT ({$e['yr']}) AS yr, ({$e['qt']}) AS qt,
                         COALESCE(NULLIF(TRIM(isl.Client_id),''), NULLIF(TRIM(ds.Customer_Id),'')) AS cid,
                         TRIM(COALESCE(isl.Client_name,'')) AS cname,
                         COALESCE(isl.Qty * isl.Unit_price, 0) AS amt,
                         COALESCE(isl.Qty, 0) AS qty,
                         (CASE WHEN COALESCE(isl.Unit_price,0) <= 0 THEN 1 ELSE 0 END) AS noprice
                  FROM is_list isl
                  LEFT JOIN is_sale_type ist ON isl.sale_type = ist.sale_type_id
                  LEFT JOIN d_setting ds     ON ds.d_id = isl.d_setting_id
                  WHERE $wSql
                    AND (ist.is_count IS NULL OR ist.is_count = 1)) t
            GROUP BY t.yr, t.qt, t.cid, t.cname";
    $st = $db->prepare($sql); $st->execute($wArgs);
    $merge($st->fetchAll(PDO::FETCH_ASSOC), 'ship');

    // ── 退貨 ir_track ──
    $e = cqa_period_expr('IR_date', $qb, $cut);
    [$wSql, $wArgs] = cqa_window_sql('IR_date', $windows);
    $sql = "SELECT t.yr, t.qt, t.cid, t.cname, SUM(t.amt) amt, SUM(t.qty) qty, COUNT(*) cnt
            FROM (SELECT ({$e['yr']}) AS yr, ({$e['qt']}) AS qt,
                         NULLIF(TRIM(ds.Customer_Id),'') AS cid,
                         TRIM(COALESCE(it.Client_name,'')) AS cname,
                         COALESCE(it.Qty * it.Unit_price, 0) AS amt,
                         COALESCE(it.Qty, 0) AS qty
                  FROM ir_track it
                  LEFT JOIN d_setting ds ON ds.d_id = it.d_setting_id
                  WHERE $wSql) t
            GROUP BY t.yr, t.qt, t.cid, t.cname";
    $st = $db->prepare($sql); $st->execute($wArgs);
    $merge($st->fetchAll(PDO::FETCH_ASSOC), 'ret');

    // ── 訂單 order_track ──
    $dateCol = ($ob === 'order') ? 'Order_date' : 'Delivery_date';
    $e = cqa_period_expr($dateCol, $qb, $cut);
    [$wSql, $wArgs] = cqa_window_sql($dateCol, $windows);
    $sql = "SELECT t.yr, t.qt, t.cid, t.cname, SUM(t.amt) amt, SUM(t.qty) qty, COUNT(*) cnt
            FROM (SELECT ({$e['yr']}) AS yr, ({$e['qt']}) AS qt,
                         COALESCE(NULLIF(TRIM(ot.Client_name_ID),''), NULLIF(TRIM(ds.Customer_Id),'')) AS cid,
                         TRIM(COALESCE(ot.Client_name,'')) AS cname,
                         COALESCE(ot.Qty * ot.unit_price, 0) AS amt,
                         COALESCE(ot.Qty, 0) AS qty
                  FROM order_track ot
                  LEFT JOIN d_setting ds ON ds.d_id = ot.d_id_ID
                  WHERE $wSql
                    AND (ot.Order_status IS NULL OR ot.Order_status <> 9)
                    AND ot.unit_price IS NOT NULL AND ot.unit_price > 0) t
            GROUP BY t.yr, t.qt, t.cid, t.cname";
    $st = $db->prepare($sql); $st->execute($wArgs);
    $merge($st->fetchAll(PDO::FETCH_ASSOC), 'ord');

    foreach ($clients as &$c) { $c['total']['net'] = $c['total']['ship'] - $c['total']['ret']; }
    unset($c);

    uasort($clients, function ($a, $b) { return $b['total']['net'] <=> $a['total']['net']; });

    return ['clients' => $clients, 'quarters' => $quarters,
            'windows' => $windows,
            'order_quality' => cqa_order_quality($db, $quarters, $dateCol, $qb, $cut, $windows),
            'meta' => ['year_from' => $yFrom, 'year_to' => $yTo, 'order_basis' => $ob,
                       'q_basis' => $qb, 'cutoff' => $cut, 'cap_days' => $cap]];
}

/**
 * 訂單資料品質：每一季「未取消的訂單張數」與「其中有填單價的張數」
 *
 * 為什麼一定要有這個：訂單金額只算得出「有填單價」的那些訂單，而實測
 * 2024 年 2,916 張訂單只有 9 張有單價、2025 年 3,410 張只有 10 張，
 * 2026 年才開始正常填。不把這件事講出來，畫面上 2025 年的訂單就是一整排 0，
 * 看起來像程式壞掉而不是資料沒有。
 * 刻意只 GROUP BY 年季、不分客戶——那 6,000 多張沒填客戶也沒填單價的訂單
 * 如果混進客戶維度，會長出一大堆「（未指定客戶）」的空列。
 */
function cqa_order_quality(PDO $db, array $quarters, string $dateCol, string $qBasis, int $cut, array $windows): array
{
    $out = [];
    try {
        $e = cqa_period_expr($dateCol, $qBasis, $cut);
        [$wSql, $wArgs] = cqa_window_sql($dateCol, $windows);
        $sql = "SELECT t.yr, t.qt, COUNT(*) total_cnt, SUM(t.priced) priced_cnt
                FROM (SELECT ({$e['yr']}) AS yr, ({$e['qt']}) AS qt,
                             (CASE WHEN COALESCE(ot.unit_price,0) > 0 THEN 1 ELSE 0 END) AS priced
                      FROM order_track ot
                      WHERE $wSql
                        AND (ot.Order_status IS NULL OR ot.Order_status <> 9)) t
                GROUP BY t.yr, t.qt";
        $st = $db->prepare($sql); $st->execute($wArgs);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $q = intval($r['qt']); if ($q < 1 || $q > 4) continue;
            $out[cqa_qkey(intval($r['yr']), $q)] = ['total' => intval($r['total_cnt']), 'priced' => intval($r['priced_cnt'])];
        }
    } catch (Throwable $e2) {}
    return $out;
}

/** 取某客戶某季的值（沒有資料回 0） */
function cqa_val(array $client, string $qkey, string $metric): float
{
    $cell = $client['q'][$qkey] ?? null;
    if (!$cell) return 0.0;
    if ($metric === 'ship') return (float)$cell['ship'];
    if ($metric === 'ord')  return (float)$cell['ord'];
    return (float)$cell['net'];
}

/**
 * 以季為單位自動判斷成長／衰退
 *
 * @param array $opt year, q, compare(yoy|qoq), metric(net|ship|ord), min_amt,
 *                   order_basis, q_basis, align(1=當季未結束時兩邊都只比到同樣天數)
 * @return array ['curr','base','rows','totals','meta']
 *   rows 每列：key,name,cid,unmatched,curr,base,diff,pct,status,streak_down,series,major
 *   status：new=新增／lost=流失／up／down／flat
 *   pct：基期為 0 時回 null（不可算成無限大或 999%）
 */
function cqa_growth(PDO $db, array $opt = []): array
{
    $year    = intval($opt['year'] ?? date('Y'));
    $q       = max(1, min(4, intval($opt['q'] ?? 1)));
    $compare = isset($opt['compare']) && isset(cqa_compares()[$opt['compare']]) ? $opt['compare'] : 'yoy';
    $metric  = isset($opt['metric'])  && isset(cqa_metrics()[$opt['metric']])   ? $opt['metric']  : 'net';
    $minAmt  = max(0, floatval($opt['min_amt'] ?? 50000));
    $align   = !isset($opt['align']) || !empty($opt['align']);
    $ob      = $opt['order_basis'] ?? 'delivery';
    $qb      = isset($opt['q_basis']) && isset(cqa_q_bases()[$opt['q_basis']]) ? $opt['q_basis'] : 'billing';
    $cut     = ($qb === 'billing') ? cqa_cutoff_day($db) : 0;

    [$by, $bq] = ($compare === 'qoq') ? cqa_qprev($year, $q) : [$year - 1, $q];

    // 當季還沒過完時，兩邊都只計到相同的天數，否則整批客戶都會看起來在衰退
    $prog = cqa_quarter_progress($db, $year, $q, $qb, $cut);
    $cap  = ($align && $prog['is_partial']) ? $prog['days_done'] : null;

    // 往前多抓一年：streak（連續下滑幾季）要看更早的季
    $data = cqa_quarter_rows($db, [
        'year_from' => min($by, $year) - 1, 'year_to' => $year,
        'order_basis' => $ob, 'q_basis' => $qb, 'cap_days' => $cap,
    ]);

    $cqk = cqa_qkey($year, $q);
    $bqk = cqa_qkey($by, $bq);

    $rows = [];
    $totCurr = 0.0; $totBase = 0.0;
    foreach ($data['clients'] as $k => $c) {
        $curr = cqa_val($c, $cqk, $metric);
        $base = cqa_val($c, $bqk, $metric);
        if (abs($curr) < 0.005 && abs($base) < 0.005) continue;     // 兩期都沒有資料就不列
        $totCurr += $curr; $totBase += $base;

        $diff = $curr - $base;
        $pct  = (abs($base) > 0.005) ? ($diff / abs($base) * 100) : null;

        if (abs($base) < 0.005 && $curr > 0)      $status = 'new';
        elseif (abs($curr) < 0.005 && $base > 0)  $status = 'lost';
        elseif ($diff > 0.005)                    $status = 'up';
        elseif ($diff < -0.005)                   $status = 'down';
        else                                      $status = 'flat';

        // 連續下滑季數（最多回看 4 季；前一季沒有資料就停，不當成下滑）
        $streak = 0; $sy = $year; $sq = $q;
        for ($i = 0; $i < 4; $i++) {
            [$py, $pq] = cqa_qprev($sy, $sq);
            $a = cqa_val($c, cqa_qkey($sy, $sq), $metric);
            $b = cqa_val($c, cqa_qkey($py, $pq), $metric);
            if (abs($b) < 0.005) break;
            if ($a < $b - 0.005) { $streak++; $sy = $py; $sq = $pq; } else break;
        }

        $series = [];
        foreach ($data['quarters'] as $qk) $series[$qk] = round(cqa_val($c, $qk, $metric), 2);

        $rows[] = ['key' => $k, 'name' => $c['name'], 'cid' => $c['cid'], 'unmatched' => $c['unmatched'],
                   'curr' => round($curr, 2), 'base' => round($base, 2), 'diff' => round($diff, 2),
                   'pct' => ($pct === null ? null : round($pct, 1)), 'status' => $status,
                   'streak_down' => $streak, 'series' => $series,
                   // 夠不夠格進主榜：兩期都是小數字的變動只是雜訊，列出來會把真正重要的淹掉
                   'major' => (abs($curr) >= $minAmt || abs($base) >= $minAmt)];
    }

    // 變化金額大的排前面（看的是「差多少錢」不是「差幾 %」——只看 % 的話
    // 1 萬變 2 萬的小客戶會永遠排在 500 萬掉到 400 萬的大客戶前面）
    usort($rows, function ($a, $b) { return abs($b['diff']) <=> abs($a['diff']); });

    $totDiff = $totCurr - $totBase;
    return [
        'curr'     => ['year' => $year, 'q' => $q,  'key' => $cqk, 'label' => cqa_qlabel($year, $q)] + $prog,
        'base'     => ['year' => $by,   'q' => $bq, 'key' => $bqk, 'label' => cqa_qlabel($by, $bq)],
        'rows'     => $rows,
        'quarters' => $data['quarters'],
        'order_quality' => $data['order_quality'],
        'totals'   => ['curr' => round($totCurr, 2), 'base' => round($totBase, 2), 'diff' => round($totDiff, 2),
                       'pct' => (abs($totBase) > 0.005 ? round($totDiff / abs($totBase) * 100, 1) : null)],
        'meta'     => ['compare' => $compare, 'metric' => $metric, 'min_amt' => $minAmt,
                       'align' => $align, 'cap_days' => $cap] + $data['meta'],
    ];
}
