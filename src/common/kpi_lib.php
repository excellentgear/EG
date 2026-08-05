<?php
/**
 * 出貨目標達成率(月份KPI週報)共用計算：抽出自 views/Sales/Shipping_Analysis_new.php 的 get_kpi_data，
 * 讓該頁與會議紀錄模組(meeting_lib.php)共用同一套邏輯，避免各自維護、也不受該頁未來存廢影響。
 */

function kpi_query_week(PDO $pdo, string $ws, string $we): array {
    $s1 = $pdo->prepare("SELECT COALESCE(SUM(Qty*Unit_price),0) AS amt, COALESCE(SUM(Qty),0) AS qty, COUNT(*) AS cnt FROM is_list WHERE Order_date BETWEEN ? AND ? AND Unit_price > 0");
    $s1->execute([$ws, $we]); $r1 = $s1->fetch(PDO::FETCH_ASSOC);
    $s2 = $pdo->prepare("SELECT COALESCE(SUM(Qty*unit_price),0) AS amt FROM order_track WHERE Delivery_date BETWEEN ? AND ? AND (Order_status IS NULL OR Order_status!=9) AND unit_price>0");
    $s2->execute([$ws, $we]); $r2 = $s2->fetch(PDO::FETCH_ASSOC);
    $s3 = $pdo->prepare("SELECT COALESCE(SUM(Qty*Unit_price),0) AS amt, COALESCE(SUM(Qty),0) AS qty, COUNT(*) AS cnt FROM ir_track WHERE IR_date BETWEEN ? AND ? AND Unit_price>0");
    $s3->execute([$ws, $we]); $r3 = $s3->fetch(PDO::FETCH_ASSOC);
    return [
        'ship_amount'   => floatval($r1['amt']),
        'ship_qty'      => floatval($r1['qty']),
        'ship_count'    => intval($r1['cnt']),
        'order_amount'  => floatval($r2['amt']),
        'return_amount' => floatval($r3['amt']),
        'return_qty'    => floatval($r3['qty']),
    ];
}

/**
 * 帳款月 4 週明細 + 合計 + 大額前三名，回傳結構與 Shipping_Analysis_new.php 的 get_kpi_data 完全一致
 * (只是不含該頁專屬的表尾設定 footer，footer 由頁面自己讀，不屬於 KPI 計算的一部分)。
 */
function kpi_weekly_report(PDO $pdo, int $year, int $month): array {
    $g_cutoff = 0;
    try { $g_cutoff = intval($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='billing_cutoff_day' LIMIT 1")->fetchColumn()); } catch (Throwable $e) {}

    $target_amount = 0;
    try {
        $gt = $pdo->query("SELECT param_value FROM system_parameters WHERE param_group='SHIPPING_ANALYSIS' AND param_key='KPI_TARGET' LIMIT 1")->fetchColumn();
        if ($gt !== false) $target_amount = floatval($gt);
    } catch (Throwable $e) {}

    $saved_stmt = $pdo->prepare("SELECT start_day FROM kpi_monthly_targets WHERE year=? AND month=?");
    $saved_stmt->execute([$year, $month]);
    $saved = $saved_stmt->fetch(PDO::FETCH_ASSOC);
    $start_day = $saved ? intval($saved['start_day']) : ($g_cutoff > 0 ? $g_cutoff + 1 : 1);
    if ($start_day > 28) $start_day = 1;

    $cutoff = ($start_day > 1) ? $start_day - 1 : 31;
    $prev_month = $month - 1; $prev_year = $year;
    if ($prev_month < 1) { $prev_month = 12; $prev_year--; }

    $_bd_start = new DateTime(sprintf('%04d-%02d-%02d', $prev_year, $prev_month, $start_day));
    $_end_day = min($cutoff, (int)(new DateTime(sprintf('%04d-%02d-01', $year, $month)))->format('t'));
    $_bd_end = new DateTime(sprintf('%04d-%02d-%02d', $year, $month, $_end_day));

    $weeks = [];
    for ($w = 0; $w < 4; $w++) {
        $ws = clone $_bd_start; $ws->modify("+".($w*7)." days");
        if ($w < 3) { $we = clone $ws; $we->modify('+6 days'); } else { $we = clone $_bd_end; }
        $weeks[] = ['start' => $ws->format('Y-m-d'), 'end' => $we->format('Y-m-d')];
    }
    $week_target = $target_amount / 4;

    $lm_month = $prev_month; $lm_year = $prev_year;
    $lm_prev_month = $lm_month - 1; $lm_prev_year = $lm_year;
    if ($lm_prev_month < 1) { $lm_prev_month = 12; $lm_prev_year--; }
    $_lm_start = new DateTime(sprintf('%04d-%02d-%02d', $lm_prev_year, $lm_prev_month, $start_day));
    $_lm_end_day = min($cutoff, (int)(new DateTime(sprintf('%04d-%02d-01', $lm_year, $lm_month)))->format('t'));
    $_lm_end = new DateTime(sprintf('%04d-%02d-%02d', $lm_year, $lm_month, $_lm_end_day));
    $last_weeks = [];
    for ($w = 0; $w < 4; $w++) {
        $ws = clone $_lm_start; $ws->modify("+".($w*7)." days");
        $we = ($w < 3) ? (clone $ws)->modify('+6 days') : clone $_lm_end;
        $last_weeks[] = ['start' => $ws->format('Y-m-d'), 'end' => $we->format('Y-m-d')];
    }

    $result_weeks = [];
    $cum_revenue = 0;
    for ($i = 0; $i < 4; $i++) {
        $d = kpi_query_week($pdo, $weeks[$i]['start'], $weeks[$i]['end']);
        $ld = kpi_query_week($pdo, $last_weeks[$i]['start'], $last_weeks[$i]['end']);
        $revenue = $d['ship_amount'] - $d['return_amount'];
        $cum_revenue += $revenue;
        $cum_target = $week_target * ($i + 1);
        $lm_revenue = $ld['ship_amount'] - $ld['return_amount'];
        $result_weeks[] = [
            'no' => $i + 1, 'start' => $weeks[$i]['start'], 'end' => $weeks[$i]['end'],
            'week_target' => round($week_target), 'cum_target' => round($cum_target),
            'order_amount' => round($d['order_amount']),
            'order_rate' => $week_target > 0 ? round($d['order_amount'] / $week_target * 100, 2) : 0,
            'ship_amount' => round($d['ship_amount']), 'return_amount' => round($d['return_amount']),
            'revenue' => round($revenue), 'cum_revenue' => round($cum_revenue),
            'revenue_rate' => $cum_target > 0 ? round($cum_revenue / $cum_target * 100, 2) : 0,
            'lm_revenue' => round($lm_revenue),
            'change_rate' => $lm_revenue > 0 ? round(($revenue - $lm_revenue) / $lm_revenue * 100, 2) : null,
        ];
    }

    $total_order = array_sum(array_column($result_weeks, 'order_amount'));
    $total_ship = array_sum(array_column($result_weeks, 'ship_amount'));
    $total_return = array_sum(array_column($result_weeks, 'return_amount'));
    $total_rev = array_sum(array_column($result_weeks, 'revenue'));

    $bd_s = $_bd_start->format('Y-m-d');
    $bd_e = $_bd_end->format('Y-m-d');
    $top_ship = $top_ship_excl = $top_order = $top_return = [];
    try {
        $st = $pdo->prepare("SELECT isl.Order_date, isl.Client_name, isl.Product_id, isl.Qty, isl.Unit_price,
            ROUND(isl.Qty * isl.Unit_price) AS amount,
            COALESCE(ist.sale_type_name, '一般產品') AS sale_type_name
            FROM is_list isl LEFT JOIN is_sale_type ist ON isl.sale_type = ist.sale_type_id
            WHERE isl.Order_date BETWEEN ? AND ? AND isl.Unit_price > 0
            AND (ist.is_count IS NULL OR ist.is_count != 0)
            ORDER BY amount DESC LIMIT 3");
        $st->execute([$bd_s, $bd_e]); $top_ship = $st->fetchAll(PDO::FETCH_ASSOC);

        $st2 = $pdo->prepare("SELECT isl.Order_date, isl.Client_name, isl.Product_id, isl.Qty, isl.Unit_price,
            ROUND(isl.Qty * isl.Unit_price) AS amount,
            COALESCE(ist.sale_type_name, '不列入') AS sale_type_name
            FROM is_list isl LEFT JOIN is_sale_type ist ON isl.sale_type = ist.sale_type_id
            WHERE isl.Order_date BETWEEN ? AND ? AND isl.Unit_price > 0
            AND ist.is_count = 0
            ORDER BY amount DESC LIMIT 3");
        $st2->execute([$bd_s, $bd_e]); $top_ship_excl = $st2->fetchAll(PDO::FETCH_ASSOC);

        $st3 = $pdo->prepare("SELECT ot.Delivery_date AS date, ot.Client_name, ot.d_id AS Product_id,
            ot.Qty, ot.unit_price AS Unit_price, ROUND(ot.Qty * ot.unit_price) AS amount
            FROM order_track ot
            WHERE ot.Delivery_date BETWEEN ? AND ? AND ot.unit_price > 0
            AND (ot.Order_status IS NULL OR ot.Order_status != 9)
            ORDER BY amount DESC LIMIT 3");
        $st3->execute([$bd_s, $bd_e]); $top_order = $st3->fetchAll(PDO::FETCH_ASSOC);

        $st4 = $pdo->prepare("SELECT irt.IR_date AS date, irt.Client_name, irt.d_id AS Product_id,
            irt.Qty, irt.Unit_price, ROUND(irt.Qty * irt.Unit_price) AS amount
            FROM ir_track irt
            WHERE irt.IR_date BETWEEN ? AND ? AND irt.Unit_price>0 AND irt.Qty > 0
            ORDER BY amount DESC LIMIT 3");
        $st4->execute([$bd_s, $bd_e]); $top_return = $st4->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    return [
        'settings' => ['year'=>$year,'month'=>$month,'target_amount'=>$target_amount,'start_day'=>$start_day,'global_cutoff'=>$g_cutoff],
        'weeks' => $result_weeks,
        'billing_start' => $bd_s,
        'billing_end' => $bd_e,
        'totals' => [
            'cum_target' => round($target_amount),
            'order_amount' => $total_order,
            'order_rate' => $target_amount > 0 ? round($total_order / $target_amount * 100, 2) : 0,
            'ship_amount' => $total_ship,
            'return_amount' => $total_return,
            'revenue' => $total_rev,
            'revenue_rate' => $target_amount > 0 ? round($total_rev / $target_amount * 100, 2) : 0,
        ],
        'top_ship' => $top_ship,
        'top_ship_excl' => $top_ship_excl,
        'top_order' => $top_order,
        'top_return' => $top_return,
    ];
}
