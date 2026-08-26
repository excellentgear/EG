<?php
/**
 * 料號製程履歷合併報告 —— 共用函式庫（2026-08-03 新建）
 * 圖面候選解析(Z:/BOM/ 精確檔名比對)、圖面方向判斷、製程清單、複驗批次歷程、
 * 報工簡表彙總、訂單/出貨頻率統計。權限：roles.module='part_process_report'（整頁單一權限，見 ai-rules）。
 */

if (!defined('PPR_BOM_SCAN_DIR')) {
    require_once __DIR__ . '/bom_dir_lib.php';   // 資料夾位置走設定鍵 bom_scan_dir，不再寫死 Z: 磁碟機代號
    define('PPR_BOM_SCAN_DIR', eg_bom_scan_dir_auto());
    define('PPR_BOM_URL_DIR', '/nas/');
    define('PPR_MAX_BATCH_COUNT', 30);   // 批次一次最多產生筆數，超過需縮小期間或手動勾選子集合
}

if (!function_exists('ppr_ensure_schema')) {

function ppr_ensure_schema(PDO $db): void {
    // role_code 在 roles 表是全站唯一(非僅模組內唯一)，一律加模組前綴避免撞名
    $st = $db->prepare("SELECT 1 FROM roles WHERE role_code='part_process_report_view' LIMIT 1");
    $st->execute();
    if (!$st->fetchColumn()) {
        $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES ('part_process_report_view','料號製程履歷報告-檢視','part_process_report')")->execute();
    }
}

/* ============================================================
 * 圖面候選解析
 * ============================================================ */
/**
 * 依 BOM 號碼精確比對 Z:/BOM/ 底下的圖檔（檔名去副檔名恰好等於 BOM 號碼者才算候選；
 * 帶任何後綴變體一律不算）。一次 scandir 處理多筆 BOM，回傳 bom => ['status','candidates'=>[...]]
 */
function ppr_resolve_drawings(array $bomNumbers): array {
    $result = [];
    foreach ($bomNumbers as $b) $result[$b] = ['status'=>'none', 'candidates'=>[]];
    if (!is_dir(PPR_BOM_SCAN_DIR) || empty($bomNumbers)) return $result;
    $want = array_flip($bomNumbers);
    $all = @scandir(PPR_BOM_SCAN_DIR);
    if ($all === false) return $result;
    foreach ($all as $fn) {
        if ($fn === '.' || $fn === '..') continue;
        $noExt = pathinfo($fn, PATHINFO_FILENAME);
        if (!isset($want[$noExt])) continue;
        $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','pdf'], true)) continue;
        $abs = PPR_BOM_SCAN_DIR . $fn;
        $result[$noExt]['candidates'][] = [
            'filename'    => $fn,
            'url'         => PPR_BOM_URL_DIR . $fn,
            'ext'         => $ext,
            'orientation' => ppr_drawing_orientation($abs, $ext),
        ];
    }
    foreach ($result as $b => &$r) {
        $n = count($r['candidates']);
        $r['status'] = $n === 0 ? 'none' : ($n === 1 ? 'single' : 'multiple');
    }
    unset($r);
    return $result;
}

/** 圖面直式/橫式判斷：getimagesize 比較寬高；PDF 無法判斷一律當直式（多數工程圖PDF為直式） */
function ppr_drawing_orientation(string $absPath, string $ext): string {
    if ($ext === 'pdf') return 'portrait';
    try {
        $sz = @getimagesize($absPath);
        if ($sz && $sz[0] > 0 && $sz[1] > 0) return $sz[0] > $sz[1] ? 'landscape' : 'portrait';
    } catch (Throwable $e) {}
    return 'portrait';
}

/* ============================================================
 * 製程清單（依 processing_sequence 排序；含拆批歷程）
 * ============================================================ */
/**
 * 回傳每個 bom_sn 一組（依代表列 processing_sequence 排序），組內 'batches' 陣列：
 *   - 若該 bom_sn 曾拆批(有任何列 batch_label 非空)：'batches' = 全部帶 batch_label 的列（含已被消耗 is_consumed=1
 *     者，因為那正是履歷要呈現的「曾經發生過的檢驗歷程」——不可用 is_consumed=0 篩掉，會把已完成的檢驗紀錄濾光）
 *   - 否則：'batches' = 該唯一一列（batch_label 為空）
 * 判斷規則與 views/pm/OreadyReply_ForPm_BaseOfTime.php 的製程欄顯示邏輯一致（該頁也未用 is_consumed 篩掉歷史）。
 */
function ppr_bom_processes(PDO $db, string $bomNo): array {
    $st = $db->prepare("
        SELECT bi.bom_ing_fid, bi.bom, bi.bom_sn, bi.process_no, pn.ProcessName,
               bi.maker_id_no, ml.maker_id AS maker_name, COALESCE(ml.internal,0) AS is_internal,
               bi.machine_id, mc.machine AS machine_name, bi.sqty,
               bi.processing_sequence, bi.processing_state, bi.QC_check, bi.QC_check_date,
               bi.outsource_date, bi.return_date, bi.qc_completed, bi.batch_label, bi.is_consumed
        FROM bom_ing bi
        LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
        LEFT JOIN maker_list ml ON ml.maker_id_no = bi.maker_id_no
        LEFT JOIN machine_list mc ON mc.machine_id = bi.machine_id
        WHERE bi.bom = ? AND bi.is_schedule_split = 0
        ORDER BY bi.bom_sn ASC, (bi.batch_label IS NULL) DESC, bi.batch_label ASC, bi.bom_ing_fid ASC");
    $st->execute([$bomNo]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $groups = [];
    foreach ($rows as $r) $groups[$r['bom_sn']][] = $r;

    $out = [];
    foreach ($groups as $bomSn => $grp) {
        $hasSplit = false;
        foreach ($grp as $g) if (!empty($g['batch_label'])) { $hasSplit = true; break; }
        $batches = $hasSplit
            ? array_values(array_filter($grp, function ($g) { return !empty($g['batch_label']); }))
            : $grp;

        // 代表列（取活躍中的那一筆決定目前流程順序位置；沒有活躍列就退回組內第一筆）
        $rep = null;
        foreach ($grp as $g) if ((int)$g['is_consumed'] === 0) { $rep = $g; break; }
        if (!$rep) $rep = $grp[0];

        $out[] = [
            'bom_sn'              => $bomSn,
            'process_no'          => $rep['process_no'],
            'ProcessName'         => $rep['ProcessName'],
            'processing_sequence' => $rep['processing_sequence'],
            'batches'             => $batches,
        ];
    }
    usort($out, function ($a, $b) {
        return ($a['processing_sequence'] ?? 999999) <=> ($b['processing_sequence'] ?? 999999);
    });
    return $out;
}

/** 依批次陣列彙總一個代表性 QC 狀態（供流程總覽步驟條使用）：異常>部分完成>合格>待驗 */
function ppr_group_status(array $batches): array {
    $hasNg = false; $checkedCnt = 0;
    foreach ($batches as $b) {
        $qc = $b['QC_check'];
        if ($qc === 'ng' || $qc === 'QQ') $hasNg = true;
        if ($qc === 'ok' || $qc === 'AOD' || (int)$b['qc_completed'] === 1) $checkedCnt++;
    }
    if ($hasNg) return ['label' => '異常', 'color' => '#DD5138'];
    if ($checkedCnt > 0 && $checkedCnt === count($batches)) return ['label' => '合格', 'color' => '#8a6d2f'];
    if ($checkedCnt > 0) return ['label' => $checkedCnt.'/'.count($batches).'已驗', 'color' => '#F0A24B'];
    return ['label' => '待驗', 'color' => '#999'];
}

/** 該料號在指定期間內共有幾筆 BOM（供搜尋建議清單/清單標題顯示筆數用） */
function ppr_bom_count_in_range(PDO $db, int $dSettingId, string $from, string $to): int {
    $where = ["b.d_setting_id = ?"]; $params = [$dSettingId];
    if ($from !== '') { $where[] = "b.Created_At >= ?"; $params[] = $from.' 00:00:00'; }
    if ($to   !== '') { $where[] = "b.Created_At <= ?"; $params[] = $to.' 23:59:59'; }
    $st = $db->prepare("SELECT COUNT(*) FROM bom b WHERE ".implode(' AND ', $where));
    $st->execute($params);
    return (int)$st->fetchColumn();
}

/* ============================================================
 * 複驗/批次檢驗歷程（qc_check_form）
 * ============================================================ */
/** 回傳依 batch_no 分組、組內依 round_no 排序的歷程；含特採(AOD)標記 */
function ppr_qc_history(PDO $db, int $bomIngFid): array {
    if ($bomIngFid <= 0) return [];
    try {
        $st = $db->prepare("
            SELECT qc_form_id, batch_no, round_no, check_result, ng_qty, status,
                   pcs_verdicts, check_date, created_at, ncr_decision
            FROM qc_check_form
            WHERE bom_ing_fid = ? AND status IN ('SUBMITTED','LOCKED')
            ORDER BY batch_no ASC, round_no ASC");
        $st->execute([$bomIngFid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }

    $batches = [];
    foreach ($rows as $r) {
        $b = (int)($r['batch_no'] ?: 1);
        $hasAod = false;
        if (!empty($r['pcs_verdicts'])) {
            $pv = json_decode($r['pcs_verdicts'], true);
            if (is_array($pv)) {
                foreach ($pv as $v) {
                    $verdict = is_array($v) ? ($v['v'] ?? '') : '';
                    if ($verdict === 'AOD') { $hasAod = true; break; }
                }
            }
        }
        $batches[$b][] = [
            'qc_form_id'   => (int)$r['qc_form_id'],
            'round_no'     => (int)($r['round_no'] ?: 1),
            'check_result' => $r['check_result'],
            'ng_qty'       => (int)$r['ng_qty'],
            'is_aod'       => $hasAod,
            'date'         => $r['check_date'] ?: substr((string)$r['created_at'], 0, 16),
            'ncr_decision' => $r['ncr_decision'],
        ];
    }
    ksort($batches);
    $out = [];
    foreach ($batches as $b => $rounds) $out[] = ['batch_no'=>$b, 'rounds'=>$rounds];
    return $out;
}

/** 單張檢驗表(qc_form_id)的量測明細（項目名稱/標準/實測值/判定），供「顯示QC檢驗內容」開關使用 */
function ppr_qc_measurements(PDO $db, int $qcFormId): array {
    if ($qcFormId <= 0) return [];
    try {
        $st = $db->prepare("
            SELECT m.sample_no, m.measured_value, m.result, m.item_verdict,
                   i.item_name, i.standard_text, i.min_value, i.max_value
            FROM qc_measurement m
            LEFT JOIN qc_inspection_item i ON i.item_id = m.item_id
            WHERE m.qc_form_id = ?
            ORDER BY i.sort_order ASC, m.item_id ASC, m.sample_no ASC");
        $st->execute([$qcFormId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/* ============================================================
 * 報工簡表（pm_process_daily_report）
 * ============================================================ */
/**
 * 彙總單一製程(bom_ing_fid)的報工資料：機台/人員/日期區間/實際加工日/總工時/單顆工時/相對效率。
 * 相對效率＝該批單顆工時 vs 該製程(process_no)歷史平均單顆工時之比值（無官方標準工時可比）。
 */
function ppr_report_work_summary(PDO $db, int $bomIngFid, int $processNo): ?array {
    if ($bomIngFid <= 0) return null;
    try {
        $st = $db->prepare("
            SELECT r.report_date, r.machine_id, mc.machine AS machine_name,
                   r.setup_user_id, r.production_user_id,
                   u1.user_cname AS setup_user_name, u2.user_cname AS production_user_name,
                   r.setup_start_time, r.setup_end_time, r.production_start_time, r.production_end_time,
                   r.produced_qty, r.is_finished
            FROM pm_process_daily_report r
            LEFT JOIN machine_list mc ON mc.machine_id = r.machine_id
            LEFT JOIN user u1 ON u1.id = r.setup_user_id
            LEFT JOIN user u2 ON u2.id = r.production_user_id
            WHERE r.bom_ing_fid = ?
            ORDER BY r.report_date ASC");
        $st->execute([$bomIngFid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return null; }
    if (empty($rows)) return null;

    $machines = []; $operators = []; $dates = []; $totalMin = 0; $qty = 0;
    foreach ($rows as $r) {
        if ($r['machine_name']) $machines[$r['machine_name']] = true;
        if ($r['setup_user_name']) $operators[$r['setup_user_name']] = true;
        if ($r['production_user_name']) $operators[$r['production_user_name']] = true;
        if ($r['report_date']) $dates[] = $r['report_date'];
        $setupMin = 0; $prodMin = 0;
        if ($r['setup_start_time'] && $r['setup_end_time']) {
            $s = strtotime($r['setup_start_time']); $e = strtotime($r['setup_end_time']);
            if ($e > $s) $setupMin = ($e - $s) / 60;
        }
        if ($r['production_start_time'] && $r['production_end_time']) {
            $s = strtotime($r['production_start_time']); $e = strtotime($r['production_end_time']);
            if ($e > $s) $prodMin = ($e - $s) / 60;
        }
        $totalMin += $setupMin + $prodMin;
        $qty += max(0, (int)$r['produced_qty']);
    }
    sort($dates);
    $pcMin = $qty > 0 ? $totalMin / $qty : null;

    // 相對效率：與同製程(process_no)歷史平均單顆工時比較
    $relEff = null;
    if ($pcMin !== null && $processNo > 0) {
        try {
            $st2 = $db->prepare("
                SELECT SUM(GREATEST(COALESCE(TIMESTAMPDIFF(MINUTE, r.setup_start_time, r.setup_end_time),0),0)
                          + GREATEST(COALESCE(TIMESTAMPDIFF(MINUTE, r.production_start_time, r.production_end_time),0),0)) AS total_min,
                       SUM(GREATEST(COALESCE(r.produced_qty,0),0)) AS qty
                FROM pm_process_daily_report r
                JOIN bom_ing bi ON bi.bom_ing_fid = r.bom_ing_fid
                WHERE bi.process_no = ? AND bi.bom_ing_fid <> ?");
            $st2->execute([$processNo, $bomIngFid]);
            $hist = $st2->fetch(PDO::FETCH_ASSOC);
            if ($hist && (float)$hist['qty'] > 0 && (float)$hist['total_min'] > 0) {
                $histPc = (float)$hist['total_min'] / (float)$hist['qty'];
                if ($histPc > 0) $relEff = round($histPc / $pcMin * 100, 1);   // >100=優於歷史平均
            }
        } catch (Throwable $e) {}
    }

    return [
        'machines'    => implode('、', array_keys($machines)),
        'operators'   => implode('、', array_keys($operators)),
        'date_from'   => $dates[0] ?? null,
        'date_to'     => end($dates) ?: null,
        'actual_dates'=> implode('、', array_unique($dates)),
        'total_hr'    => round($totalMin / 60, 2),
        'produced_qty'=> $qty,
        'pc_min'      => $pcMin !== null ? round($pcMin, 2) : null,
        'rel_efficiency' => $relEff,
    ];
}

/* ============================================================
 * 訂單 / 出貨頻率分析（依 d_setting_id 歸戶，禁用料號字串 join）
 * ============================================================ */
function ppr_order_history(PDO $db, int $dSettingId): array {
    $st = $db->prepare("
        SELECT Order_id, Order_oo, Order_date, Client_name, Qty, unit_price, currency, exchange_rate
        FROM order_track WHERE d_id_ID = ? AND parent_order_id IS NULL
        ORDER BY Order_date DESC");
    $st->execute([$dSettingId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return ppr_freq_stats($rows, 'Order_date', 'Qty');
}

function ppr_ship_history(PDO $db, int $dSettingId): array {
    $st = $db->prepare("
        SELECT isl.IS_number, isl.Order_date, isl.Client_name, isl.Qty, isl.Unit_price
        FROM is_list isl
        LEFT JOIN is_sale_type ist ON ist.sale_type_id = isl.sale_type
        WHERE isl.d_setting_id = ? AND (ist.is_count IS NULL OR ist.is_count = 1)
        ORDER BY isl.Order_date DESC");
    $st->execute([$dSettingId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return ppr_freq_stats($rows, 'Order_date', 'Qty');
}

/** 共用：由日期序列算平均間隔天數、平均數量、筆數 */
function ppr_freq_stats(array $rows, string $dateKey, string $qtyKey): array {
    $dates = [];
    $qtySum = 0;
    foreach ($rows as $r) {
        if (!empty($r[$dateKey])) $dates[] = substr($r[$dateKey], 0, 10);
        $qtySum += (float)($r[$qtyKey] ?? 0);
    }
    sort($dates);
    $avgInterval = null;
    if (count($dates) >= 2) {
        $first = strtotime($dates[0]); $last = strtotime(end($dates));
        $span = ($last - $first) / 86400;
        $avgInterval = $span > 0 ? round($span / (count($dates) - 1), 1) : 0;
    }
    return [
        'rows'         => $rows,
        'count'        => count($rows),
        'avg_qty'      => count($rows) > 0 ? round($qtySum / count($rows), 2) : null,
        'avg_interval' => $avgInterval,
    ];
}

}
