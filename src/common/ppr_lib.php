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
               bi.maker_id_no, COALESCE(NULLIF(ml.maker_id,''), NULLIF(bi.maker_id,'')) AS maker_name,
               COALESCE(ml.internal,0) AS is_internal,
               bi.machine_id, mc.machine AS machine_name, NULLIF(TRIM(mc.field_no),'') AS machine_field_no,
               bi.sqty,
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
    // 製程先後順序一律以 bom_sn 為準（10/20/30/40…），不可用 processing_sequence：後者是「生管排程順序」，
    // 全站多數列是 NULL（例 B-1150825009 四站只有齒研那一站有值 42），拿它排序會讓有值的那一站被排到最前面，
    // 畫面上就會出現「齒研排在客供料之前」這種對不上 OreadyReply 製程欄的錯誤順序（2026-09-17 使用者回報）。
    usort($out, function ($a, $b) {
        return ((int)$a['bom_sn']) <=> ((int)$b['bom_sn']);
    });
    return $out;
}

/** 機台顯示名稱：一律優先用現場編號(field_no)，比照 views/pm/process_schedule_NOW.php 的顯示口徑 */
function ppr_machine_label(?string $machineName, ?string $fieldNo): string {
    $fieldNo = trim((string)$fieldNo);
    if ($fieldNo !== '') return $fieldNo;
    return trim((string)$machineName);
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

/* ============================================================
 * 料號 ↔ 製令 / 訂單 的歸戶條件
 * ============================================================ */
/**
 * 【重要】`bom.d_setting_id`（整數外鍵）全站 12,112 筆裡有 9,694 筆是 NULL（八成），真正填著料號的是
 * 文字欄 `bom.d_id`；`order_track.d_id_ID` 同樣有 5,214/9,386 筆是 NULL。所以只用整數外鍵比對，
 * 八成的製令與六成的訂單會整批查不到，而且畫面只會寫「此期間查無製令資料」不報錯
 * （2026-09-17 使用者回報 B-1140807011 查得到卻進不了報告，即為此因）。
 *
 * 本組函式回傳「這個料號的製令／訂單」條件片段：主鍵對得上就用主鍵，對不上才用料號文字回退。
 * 料號文字在 d_setting 有 1,670 組重複（同料號不同客戶各建一筆），所以文字回退**一定要再比客戶名稱**，
 * 否則會把別家客戶的同名料號一起撈進來；客戶欄空白者無從判斷，一律放行（寧可多一筆也不要整筆消失）。
 */
function ppr_part_row(PDO $db, int $partPk): ?array {
    static $cache = [];
    if (array_key_exists($partPk, $cache)) return $cache[$partPk];
    $st = $db->prepare("SELECT d.d_id, d.D_Setting_Id, d.Drawing_No, d.Spec_No, d.Type, d.Customer_Id, d.Revision,
                               c.customer AS customer_name
                        FROM d_setting d
                        LEFT JOIN customer_list c ON c.customer_id = d.Customer_Id
                        WHERE d.d_id = ? LIMIT 1");
    $st->execute([$partPk]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($row) {
        $st2 = $db->prepare("SELECT COUNT(*) FROM d_setting WHERE D_Setting_Id = ?");
        $st2->execute([$row['D_Setting_Id']]);
        $row['part_no_is_dup'] = ((int)$st2->fetchColumn() > 1);
    }
    return $cache[$partPk] = $row;
}

/**
 * 反向解析：一筆 bom 列（需含 d_setting_id / d_id / Client_Name）屬於 d_setting 的哪一筆（回傳 d_id 主鍵）。
 * d_setting_id 有值就直接用；沒有才用料號文字找，同名料號有多筆時以客戶對得上的那一筆優先。
 */
function ppr_resolve_bom_part(PDO $db, array $bomRow): ?int {
    $pk = (int)($bomRow['d_setting_id'] ?? 0);
    if ($pk > 0) return $pk;
    $txt = trim((string)($bomRow['d_id'] ?? ''));
    if ($txt === '') return null;
    $client = trim((string)($bomRow['Client_Name'] ?? ''));
    try {
        $st = $db->prepare("
            SELECT d.d_id
            FROM d_setting d
            LEFT JOIN customer_list c ON c.customer_id = d.Customer_Id
            WHERE d.D_Setting_Id = ?
            ORDER BY (c.customer <=> ?) DESC, d.d_id ASC
            LIMIT 1");
        $st->execute([$txt, ($client !== '' ? $client : null)]);
        $v = $st->fetchColumn();
        return ($v !== false && $v !== null) ? (int)$v : null;
    } catch (Throwable $e) { return null; }
}

/** 回傳 [SQL 條件片段, 參數]；$pkCol=整數外鍵欄、$txtCol=料號文字欄、$clientCol=客戶名稱欄 */
function ppr_part_match_cond(array $part, string $alias, string $pkCol, string $txtCol, string $clientCol): array {
    $params   = [(int)$part['d_id']];
    $fallback = "$alias.$pkCol IS NULL AND $alias.$txtCol = ?";
    $params[] = (string)$part['D_Setting_Id'];
    if (!empty($part['part_no_is_dup'])) {
        $fallback .= " AND ($alias.$clientCol = ? OR $alias.$clientCol IS NULL OR $alias.$clientCol = '')";
        $params[]  = (string)($part['customer_name'] ?? '');
    }
    return ["($alias.$pkCol = ? OR ($fallback))", $params];
}

/** 該料號在指定期間內共有幾筆 BOM（供搜尋建議清單/清單標題顯示筆數用） */
function ppr_bom_count_in_range(PDO $db, array $part, string $from, string $to): int {
    [$cond, $params] = ppr_part_match_cond($part, 'b', 'd_setting_id', 'd_id', 'Client_Name');
    $where = [$cond];
    if ($from !== '') { $where[] = "b.Created_At >= ?"; $params[] = $from.' 00:00:00'; }
    if ($to   !== '') { $where[] = "b.Created_At <= ?"; $params[] = $to.' 23:59:59'; }
    $st = $db->prepare("SELECT COUNT(*) FROM bom b WHERE ".implode(' AND ', $where));
    $st->execute($params);
    return (int)$st->fetchColumn();
}

/* ============================================================
 * 製令建立～結案日期
 * ============================================================ */
/** BOM 編號 B-YYYMMDDNNN 回推日期（YYY＝民國年3碼），與 OreadyReply_completed_query.php 同一套推算 */
function ppr_bom_no_date(string $bom): ?string {
    if (!preg_match('~-(\d{3})(\d{2})(\d{2})\d*$~', $bom, $m)) return null;
    $y = (int)$m[1] + 1911;
    $mm = (int)$m[2]; $dd = (int)$m[3];
    if ($mm < 1 || $mm > 12 || $dd < 1 || $dd > 31) return null;
    return sprintf('%04d-%02d-%02d', $y, $mm, $dd);
}

/**
 * 結案判定＝`bom.processing_state='1'`（與「已完工BOM查詢列印」同一口徑）。
 * 結案日只認 closed_at。**刻意不像 OreadyReply_completed_query.php 那樣退回「BOM 編號回推日期」**——
 * 那個推算值算出來的是製令「建立」的日期，拿來當結案日會印出「2025.08.11～2025.08.07」這種結案早於建立的
 * 荒謬區間（實測 B-1140807011 即如此）。2026-05-22 手動結案功能上線前的舊資料（約佔已結案的 92%）
 * 本來就沒有結案時間可查，一律誠實顯示「已結案（無結案日期紀錄）」，不要自己編一個日期出來。
 */
function ppr_bom_period(array $bomRow): array {
    $created = substr((string)($bomRow['Created_At'] ?? ''), 0, 10);
    if ($created === '' || $created === '0000-00-00') $created = ppr_bom_no_date((string)($bomRow['bom'] ?? '')) ?? '';
    $isClosed = ((string)($bomRow['processing_state'] ?? '') === '1');
    $closed = '';
    if ($isClosed) {
        $closed = substr((string)($bomRow['closed_at'] ?? ''), 0, 10);
        if ($closed === '0000-00-00') $closed = '';
    }
    return ['from'=>$created, 'to'=>$closed, 'closed'=>$isClosed, 'no_close_date'=>($isClosed && $closed === '')];
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
            SELECT r.report_date, r.machine_id,
                   COALESCE(NULLIF(TRIM(mc.field_no),''), mc.machine) AS machine_name,
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
/**
 * 歷史訂單：同一個料號可能是不同「加工項目」下的單（客戶有時只送來做齒研、有時做全製），
 * 所以一定要把 order_track.Processing_items 一起帶出來，否則清單上兩筆單價差很多會看不出原因
 * （2026-09-17 使用者要求）。歸戶走 ppr_part_match_cond（d_id_ID 有 56% 是 NULL）。
 */
function ppr_order_history(PDO $db, array $part): array {
    [$cond, $params] = ppr_part_match_cond($part, 'o', 'd_id_ID', 'd_id', 'Client_name');
    $st = $db->prepare("
        SELECT o.Order_id, o.Order_oo, o.Order_date, o.Client_name, o.Qty, o.unit_price,
               o.currency, o.exchange_rate, o.Processing_items
        FROM order_track o
        WHERE $cond AND o.parent_order_id IS NULL
        ORDER BY o.Order_date DESC");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return ppr_freq_stats($rows, 'Order_date', 'Qty');
}

/** 歷史出貨：is_list.d_setting_id 全站 37,869 筆皆有值，故維持以主鍵歸戶（見記憶 ship_stats_by_dsetting_id）；
 *  製程取該出貨綁定訂單的加工項目，未綁訂單者留白。 */
function ppr_ship_history(PDO $db, array $part): array {
    $st = $db->prepare("
        SELECT isl.IS_number, isl.Order_date, isl.Client_name, isl.Qty, isl.Unit_price,
               ot.Processing_items
        FROM is_list isl
        LEFT JOIN is_sale_type ist ON ist.sale_type_id = isl.sale_type
        LEFT JOIN order_track ot ON ot.Order_id = isl.Order_id
        WHERE isl.d_setting_id = ? AND (ist.is_count IS NULL OR ist.is_count = 1)
        ORDER BY isl.Order_date DESC");
    $st->execute([(int)$part['d_id']]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return ppr_freq_stats($rows, 'Order_date', 'Qty');
}

/* ============================================================
 * 同料號歷史報工 / 歷史加工價格（兩個都是選配，預設不查）
 * ============================================================ */
/**
 * 同一個料號、同一個製程的近期報工（供「這次做得算快還算慢」對照用）。
 * 以相同機台優先排在前面，但不同機台也一併列出（使用者明確要求）；$excludeFid＝本次這一站不重複列。
 */
function ppr_part_work_history(PDO $db, array $part, int $processNo, int $excludeFid, string $preferMachine = '', int $limit = 5): array {
    if ($processNo <= 0) return [];
    [$cond, $params] = ppr_part_match_cond($part, 'b', 'd_setting_id', 'd_id', 'Client_Name');
    try {
        $st = $db->prepare("
            SELECT bi.bom_ing_fid, bi.bom,
                   MIN(r.report_date) AS date_from, MAX(r.report_date) AS date_to,
                   COUNT(DISTINCT r.report_date) AS day_cnt,
                   GROUP_CONCAT(DISTINCT COALESCE(NULLIF(TRIM(mc.field_no),''), mc.machine) SEPARATOR '、') AS machines,
                   SUM(GREATEST(COALESCE(TIMESTAMPDIFF(MINUTE, r.setup_start_time, r.setup_end_time),0),0)
                     + GREATEST(COALESCE(TIMESTAMPDIFF(MINUTE, r.production_start_time, r.production_end_time),0),0)) AS total_min,
                   SUM(GREATEST(COALESCE(r.produced_qty,0),0)) AS qty
            FROM pm_process_daily_report r
            JOIN bom_ing bi ON bi.bom_ing_fid = r.bom_ing_fid
            JOIN bom b ON b.bom = bi.bom
            LEFT JOIN machine_list mc ON mc.machine_id = r.machine_id
            WHERE bi.process_no = ? AND bi.bom_ing_fid <> ? AND $cond
            GROUP BY bi.bom_ing_fid, bi.bom
            HAVING qty > 0 OR total_min > 0
            ORDER BY date_to DESC
            LIMIT 40");
        $st->execute(array_merge([$processNo, $excludeFid], $params));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }

    $out = [];
    foreach ($rows as $r) {
        $qty = (int)$r['qty']; $min = (float)$r['total_min'];
        $out[] = [
            'bom'       => $r['bom'],
            'machines'  => (string)($r['machines'] ?? ''),
            'date_from' => $r['date_from'],
            'date_to'   => $r['date_to'],
            'day_cnt'   => (int)$r['day_cnt'],
            'total_hr'  => round($min / 60, 2),
            'pc_min'    => $qty > 0 ? round($min / $qty, 2) : null,
            'qty'       => $qty,
            'same_machine' => ($preferMachine !== '' && strpos((string)$r['machines'], $preferMachine) !== false),
        ];
    }
    // 相同機台排前面，其餘依日期新→舊（穩定排序：同組維持原本的日期序）
    usort($out, function ($a, $b) {
        if ($a['same_machine'] !== $b['same_machine']) return $a['same_machine'] ? -1 : 1;
        return strcmp((string)$b['date_to'], (string)$a['date_to']);
    });
    return array_slice($out, 0, max(1, $limit));
}

/**
 * 此料號、此製程的歷史加工單價（優先同一廠商；同廠商查無紀錄才放寬到所有廠商並標示 scope='any'）。
 * 單價口徑與成本推算一致：modified_unit_price 優先，否則 price；0 元的不列（那是尚未填價的列）。
 */
function ppr_process_price_history(PDO $db, string $partNo, int $processNo, ?string $makerIdNo, int $limit = 5): array {
    if ($partNo === '' || $processNo <= 0) return ['rows'=>[], 'scope'=>'none'];
    $run = function (?string $maker) use ($db, $partNo, $processNo, $limit) {
        $sql = "
            SELECT t.transfer_date, t.bom,
                   IF(t.modified_unit_price>0, t.modified_unit_price, t.price) AS unit_price,
                   COALESCE(NULLIF(t.paid_qty,0), NULLIF(t.transfer_qty,0), NULLIF(t.sqty,0)) AS qty,
                   COALESCE(NULLIF(ml.maker_id,''), t.maker_from) AS maker_name
            FROM bom_ing_transfer_log t
            LEFT JOIN maker_list ml ON ml.maker_id_no = t.maker_from
            WHERE t.product_id = ?
              AND IF(t.modified_unit_price>0, t.modified_unit_price, COALESCE(t.price,0)) > 0
              AND EXISTS (SELECT 1 FROM bom_ing bi WHERE bi.bom = t.bom AND bi.bom_sn = t.bom_sn AND bi.process_no = ?)";
        $params = [$partNo, $processNo];
        if ($maker !== null && $maker !== '') { $sql .= " AND t.maker_from = ?"; $params[] = $maker; }
        $sql .= " ORDER BY t.transfer_date DESC, t.transfer_id DESC LIMIT " . (int)max(1, $limit);
        $st = $db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };
    try {
        if ($makerIdNo !== null && $makerIdNo !== '') {
            $rows = $run($makerIdNo);
            if (!empty($rows)) return ['rows'=>$rows, 'scope'=>'maker'];
        }
        $rows = $run(null);
        return ['rows'=>$rows, 'scope'=>(empty($rows) ? 'none' : 'any')];
    } catch (Throwable $e) { return ['rows'=>[], 'scope'=>'none']; }
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
