<?php
/**
 * 料號製程履歷報告 —— 成本推算共用庫（2026-08-03 新建）
 *
 * 公式口徑**複製自** views/Sales/Order_Profit_Analysis.php（opa_machine_rates/opa_inhouse_estimates/
 * opa_process_settings/外包加權平均單價區塊），刻意不改動該檔案（鐵律4：不重構已正常運作的程式），
 * 只是把同一套公式搬來以「單一製令(bom)」為粒度重新組裝（OPA 是以「訂單」為粒度彙總多筆製令）。
 * 若日後 OPA 的公式調整，這裡要記得手動同步。
 *
 * 口徑摘要：
 *   單顆成本 = 該製令(bom)底下各製程(bom_sn)成本相加，每個製程依序取：
 *     ① 外包實價（bom_ing_transfer_log 加權平均，modified_unit_price 優先）
 *     ② 無實價 → 廠內報工推算（Σ機台費率×(架機+加工工時)÷產出數）
 *     ③ 都沒有 → 製程成本例外設定(opa_process_cost_setting) 的固定單價，或標記「不計」
 *   客供料製程（ProcessNo=138 或名稱含「客供料」）不列入成本涵蓋計算。
 */

if (!function_exists('ppc_ensure_rate_columns')) {

function ppc_ensure_rate_columns(PDO $pdo): void {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM kpi_machine_asset")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('hourly_labor_cost', $cols, true)) {
            $pdo->exec("ALTER TABLE kpi_machine_asset ADD COLUMN hourly_labor_cost DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER monthly_work_hours");
        }
        if (!in_array('hourly_overhead_cost', $cols, true)) {
            $pdo->exec("ALTER TABLE kpi_machine_asset ADD COLUMN hourly_overhead_cost DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER hourly_labor_cost");
        }
        if (!in_array('annual_consumable_cost', $cols, true)) {
            $pdo->exec("ALTER TABLE kpi_machine_asset ADD COLUMN annual_consumable_cost DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER hourly_overhead_cost");
        }
    } catch (Throwable $e) {}
}

/** machine_id => ['name','rate','dep','cons','labor','overhead'] */
function ppc_machine_rates(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    ppc_ensure_rate_columns($pdo);
    $map = [];
    try {
        $rows = $pdo->query("
            SELECT ml.machine_id, ml.machine,
                   kma.purchase_amount, kma.residual_value, kma.depreciation_years, kma.monthly_work_hours,
                   COALESCE(kma.hourly_labor_cost, 0) AS labor, COALESCE(kma.hourly_overhead_cost, 0) AS overhead,
                   COALESCE(kma.annual_consumable_cost, 0) AS consumable
            FROM machine_list ml
            LEFT JOIN kpi_machine_asset kma ON kma.machine_id = ml.machine_id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $dep = 0; $cons = 0;
            $yrs = floatval($r['depreciation_years'] ?? 0);
            $mwh = floatval($r['monthly_work_hours'] ?? 0);
            if ($yrs > 0 && $mwh > 0) {
                $dep = (floatval($r['purchase_amount']) - floatval($r['residual_value'])) / $yrs / 12 / $mwh;
                if ($dep < 0) $dep = 0;
            }
            if ($mwh > 0) $cons = max(0, floatval($r['consumable'])) / 12 / $mwh;
            $map[intval($r['machine_id'])] = [
                'name'     => $r['machine'],
                'dep'      => round($dep, 2),
                'cons'     => round($cons, 2),
                'labor'    => floatval($r['labor']),
                'overhead' => floatval($r['overhead']),
                'rate'     => round($dep + $cons + floatval($r['labor']) + floatval($r['overhead']), 2),
            ];
        }
    } catch (Throwable $e) {}
    return $cache = $map;
}

/** 製程成本例外設定（與 OPA 共用同一張表，管理員在 OPA 設的例外這裡也套用一致口徑） */
function ppc_process_settings(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $map = [];
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS opa_process_cost_setting (
            process_no INT NOT NULL PRIMARY KEY,
            mode ENUM('ignore','fixed') NOT NULL DEFAULT 'ignore',
            fixed_price DECIMAL(12,4) NOT NULL DEFAULT 0,
            note VARCHAR(100) NULL,
            updated_by INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='訂單毛利分析：製程成本例外設定（不計/固定單價）'");
        foreach ($pdo->query("SELECT process_no, mode, fixed_price FROM opa_process_cost_setting")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[intval($r['process_no'])] = ['mode' => $r['mode'], 'price' => floatval($r['fixed_price'])];
        }
    } catch (Throwable $e) {}
    return $cache = $map;
}

/** 客供料製程集合：ProcessNo 138 或名稱含「客供料」 */
function ppc_kg_set(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $kg = [];
    try { $kg = $pdo->query("SELECT ProcessNo FROM process_no WHERE ProcessNo=138 OR ProcessName LIKE '%客供料%'")->fetchAll(PDO::FETCH_COLUMN) ?: []; } catch (Throwable $e) {}
    $kg = array_map('intval', $kg);
    return $cache = (empty($kg) ? [138] : $kg);
}

/** 分批 IN 查詢（同 OPA opa_chunked_in，避免單一 SQL IN 過長） */
function ppc_chunked_in(PDO $pdo, string $sqlTpl, array $ids, int $chunk = 500, array $extraBefore = [], array $extraAfter = []): array {
    $out = [];
    foreach (array_chunk($ids, $chunk) as $part) {
        $ph = implode(',', array_fill(0, count($part), '?'));
        $st = $pdo->prepare(str_replace('{IN}', $ph, $sqlTpl));
        $st->execute(array_merge($extraBefore, $part, $extraAfter));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = $r;
    }
    return $out;
}

/** 外包加權平均單價：bom|bom_sn => price；同時回傳 pricedSet 供估算/固定價排除已有實價者 */
function ppc_outsource_prices(PDO $pdo, array $boms): array {
    $perKey = []; $pricedSet = [];
    if (empty($boms)) return ['perKey'=>$perKey, 'pricedSet'=>$pricedSet];
    $inhouse = [];
    try { $inhouse = $pdo->query("SELECT maker_id_no FROM maker_list WHERE internal=1")->fetchAll(PDO::FETCH_COLUMN) ?: []; } catch (Throwable $e) {}
    $notIn = '';
    if (!empty($inhouse)) {
        $notIn = " AND (t.maker_from IS NULL OR t.maker_from NOT IN (" . implode(',', array_fill(0, count($inhouse), '?')) . "))";
    }
    foreach (array_chunk($boms, 500) as $part) {
        $ph = implode(',', array_fill(0, count($part), '?'));
        $sql = "
            SELECT t.bom, t.bom_sn,
                   SUM( IF(t.modified_unit_price>0, t.modified_unit_price, t.price)
                        * COALESCE(NULLIF(t.paid_qty,0), NULLIF(t.transfer_qty,0), NULLIF(t.sqty,0), 1) )
                 / SUM( COALESCE(NULLIF(t.paid_qty,0), NULLIF(t.transfer_qty,0), NULLIF(t.sqty,0), 1) ) AS avg_p
            FROM bom_ing_transfer_log t
            WHERE t.bom IN ($ph)
              AND IF(t.modified_unit_price>0, t.modified_unit_price, COALESCE(t.price,0)) > 0
              $notIn
            GROUP BY t.bom, t.bom_sn";
        $st = $pdo->prepare($sql);
        $st->execute(array_merge($part, $inhouse));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $p = floatval($r['avg_p']);
            if ($p <= 0) continue;
            $key = $r['bom'] . '|' . $r['bom_sn'];
            $perKey[$key] = round($p, 4);
            $pricedSet[$key] = true;
        }
    }
    return ['perKey'=>$perKey, 'pricedSet'=>$pricedSet];
}

/** 廠內報工推算成本：bom|bom_sn => ['price','setup_hr','prod_hr','qty','machines']（外包已有實價者跳過） */
function ppc_inhouse_estimates(PDO $pdo, array $boms, array $skipSet, array $rates): array {
    $out = [];
    if (empty($boms) || empty($rates)) return $out;
    $rows = ppc_chunked_in($pdo,
        "SELECT bi.bom, bi.bom_sn, r.machine_id,
                SUM(GREATEST(COALESCE(TIMESTAMPDIFF(MINUTE, r.setup_start_time, r.setup_end_time), 0), 0)) AS setup_min,
                SUM(GREATEST(COALESCE(TIMESTAMPDIFF(MINUTE, r.production_start_time, r.production_end_time), 0), 0)) AS prod_min,
                SUM(GREATEST(COALESCE(r.produced_qty, 0), 0)) AS qty
         FROM pm_process_daily_report r
         JOIN bom_ing bi ON bi.bom_ing_fid = r.bom_ing_fid
         WHERE bi.bom IN ({IN})
         GROUP BY bi.bom, bi.bom_sn, r.machine_id", $boms);
    $agg = [];
    foreach ($rows as $r) {
        $key = $r['bom'] . '|' . $r['bom_sn'];
        if (isset($skipSet[$key])) continue;
        $mid = intval($r['machine_id']);
        if (!isset($rates[$mid]) || $rates[$mid]['rate'] <= 0) continue;
        $hours = (floatval($r['setup_min']) + floatval($r['prod_min'])) / 60;
        if ($hours <= 0) continue;
        if (!isset($agg[$key])) $agg[$key] = ['cost'=>0, 'qty'=>0, 'setup_min'=>0, 'prod_min'=>0, 'machines'=>[]];
        $agg[$key]['cost']      += $hours * $rates[$mid]['rate'];
        $agg[$key]['qty']       += intval($r['qty']);
        $agg[$key]['setup_min'] += floatval($r['setup_min']);
        $agg[$key]['prod_min']  += floatval($r['prod_min']);
        $agg[$key]['machines'][] = $rates[$mid]['name'];
    }
    foreach ($agg as $key => $a) {
        if ($a['qty'] <= 0 || $a['cost'] <= 0) continue;
        $out[$key] = [
            'price'    => round($a['cost'] / $a['qty'], 4),
            'setup_hr' => round($a['setup_min'] / 60, 2),
            'prod_hr'  => round($a['prod_min'] / 60, 2),
            'qty'      => $a['qty'],
            'machines' => implode('、', array_values(array_unique($a['machines']))),
        ];
    }
    return $out;
}

/**
 * 單一/多筆製令(bom)成本明細與總計（口徑同 Order_Profit_Analysis.php）。
 * $bomNumbers: bom.bom 文字陣列
 * 回傳 bom => [
 *   'cost_pc'=>單顆成本合計(null=完全無資料), 'status'=>'full'|'partial'|'none',
 *   'proc_total','proc_priced','proc_est','proc_fixed','proc_ign','proc_kg' (製程數統計),
 *   'process_detail' => [ bom_sn => ['process_no','process_name','source'=>'outsource|inhouse|fixed|ignore|kg','price'=>float|null,'note'=>string] ]
 * ]
 */
function ppc_bom_cost(PDO $pdo, array $bomNumbers): array {
    $bomNumbers = array_values(array_unique(array_filter($bomNumbers, function($b){ return $b !== '' && $b !== null; })));
    if (empty($bomNumbers)) return [];

    $kgSet = ppc_kg_set($pdo);
    $procRows = ppc_chunked_in($pdo,
        "SELECT bi.bom, bi.bom_sn, bi.process_no, pn.ProcessName FROM bom_ing bi
         LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
         WHERE bi.bom IN ({IN}) ORDER BY bi.bom, bi.processing_sequence ASC, bi.bom_sn ASC", $bomNumbers);

    $outsource = ppc_outsource_prices($pdo, $bomNumbers);
    $rates     = ppc_machine_rates($pdo);
    $inhouse   = ppc_inhouse_estimates($pdo, $bomNumbers, $outsource['pricedSet'], $rates);
    $pcs       = ppc_process_settings($pdo);

    $result = [];
    foreach ($procRows as $r) {
        $bom = $r['bom']; $key = $bom . '|' . $r['bom_sn']; $pn = intval($r['process_no']);
        if (!isset($result[$bom])) {
            $result[$bom] = ['cost_pc'=>0, 'has_cost'=>false, 'status'=>'none',
                'proc_total'=>0,'proc_priced'=>0,'proc_est'=>0,'proc_fixed'=>0,'proc_ign'=>0,'proc_kg'=>0,
                'process_detail'=>[]];
        }
        $row = &$result[$bom];
        if (in_array($pn, $kgSet, true)) {
            $row['proc_kg']++;
            $row['process_detail'][$key] = ['process_no'=>$pn, 'process_name'=>$r['ProcessName'], 'source'=>'kg', 'price'=>null, 'note'=>'客供料，不計成本'];
            unset($row); continue;
        }
        $row['proc_total']++;
        if (isset($outsource['perKey'][$key])) {
            $p = $outsource['perKey'][$key];
            $row['cost_pc'] += $p; $row['has_cost'] = true; $row['proc_priced']++;
            $row['process_detail'][$key] = ['process_no'=>$pn, 'process_name'=>$r['ProcessName'], 'source'=>'outsource', 'price'=>$p, 'note'=>'外包實際發包單價(加權平均)'];
        } elseif (isset($inhouse[$key])) {
            $p = $inhouse[$key]['price'];
            $row['cost_pc'] += $p; $row['has_cost'] = true; $row['proc_est']++;
            $row['process_detail'][$key] = ['process_no'=>$pn, 'process_name'=>$r['ProcessName'], 'source'=>'inhouse', 'price'=>$p,
                'note'=>'廠內報工推算（'.$inhouse[$key]['machines'].'，架機'.$inhouse[$key]['setup_hr'].'時+加工'.$inhouse[$key]['prod_hr'].'時÷'.$inhouse[$key]['qty'].'件）'];
        } elseif (isset($pcs[$pn]) && $pcs[$pn]['mode'] === 'fixed' && $pcs[$pn]['price'] > 0) {
            $p = $pcs[$pn]['price'];
            $row['cost_pc'] += $p; $row['has_cost'] = true; $row['proc_fixed']++;
            $row['process_detail'][$key] = ['process_no'=>$pn, 'process_name'=>$r['ProcessName'], 'source'=>'fixed', 'price'=>$p, 'note'=>'製程成本例外設定：固定單價'];
        } else {
            $row['proc_ign']++;
            $row['process_detail'][$key] = ['process_no'=>$pn, 'process_name'=>$r['ProcessName'], 'source'=>'none', 'price'=>null, 'note'=>'無外包實價/報工資料/固定單價設定'];
        }
        unset($row);
    }
    foreach ($result as $bom => &$row) {
        $effTotal  = $row['proc_total'];
        $uncovered = max(0, $effTotal - $row['proc_priced'] - $row['proc_est'] - $row['proc_fixed']);
        if (!$row['has_cost']) { $row['status'] = 'none'; $row['cost_pc'] = null; }
        elseif ($uncovered > 0) { $row['status'] = 'partial'; $row['cost_pc'] = round($row['cost_pc'], 4); }
        else { $row['status'] = 'full'; $row['cost_pc'] = round($row['cost_pc'], 4); }
        unset($row['has_cost']);
    }
    unset($row);
    return $result;
}

/**
 * 該製令(bom)綁定的訂單：優先序 bom_order_process_map > bom.o_order_id（同 OPA）。
 * $bomRow 需含 'bom' 與 'o_order_id'。回傳 order_track 一列或 null。
 */
function ppc_bom_order(PDO $pdo, array $bomRow): ?array {
    $oid = null;
    try {
        $st = $pdo->prepare("SELECT order_id FROM bom_order_process_map WHERE bom=? LIMIT 1");
        $st->execute([$bomRow['bom']]);
        $v = $st->fetchColumn();
        if ($v !== false) $oid = (int)$v;
    } catch (Throwable $e) {}
    if (!$oid && !empty($bomRow['o_order_id'])) $oid = (int)$bomRow['o_order_id'];
    if (!$oid) return null;
    try {
        $st = $pdo->prepare("SELECT Order_id, Order_oo, d_id, Client_name, Qty, Order_date, unit_price, currency, exchange_rate FROM order_track WHERE Order_id=? LIMIT 1");
        $st->execute([$oid]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}

/** 依單顆成本與綁定訂單算毛利（無訂單或無成本回傳 null 欄位） */
function ppc_margin(?float $costPc, ?array $order): array {
    if ($costPc === null || !$order) {
        return ['unit_price'=>null, 'margin_pc'=>null, 'margin_rate'=>null];
    }
    $rate = floatval($order['exchange_rate'] ?? 0); if ($rate <= 0) $rate = 1;
    $unitPrice = floatval($order['unit_price']) * $rate;
    $marginPc = $unitPrice - $costPc;
    $marginRate = $unitPrice > 0 ? round($marginPc / $unitPrice * 100, 2) : null;
    return ['unit_price'=>round($unitPrice, 4), 'margin_pc'=>round($marginPc, 4), 'margin_rate'=>$marginRate];
}

}
