<?php
/**
 * qa_abnormal_analysis_lib.php — 品質異常分析（views/QA/qa_abnormal_analysis.php）唯一實作
 * 建立：2026-09-24
 *
 * 來源資料＝品質異常處理單（qa_abnormal_order 及其子表，見 qa_abnormal_lib.php）。這一支只做
 * 「讀」與「彙整」，不新增/修改任何一張異常單的欄位或流程——分析頁與單張處理頁完全分開，
 * 改分析口徑不會影響任何人正在填寫的單。
 *
 * 【資料範圍：只算「邏輯上的葉列」】
 * 異常單可以在裁示時拆分成好幾張子單（parent_order_id），拆分後金額/數量已經分散在各子單，
 * 原始母單只是一個殼——分析時只採計「沒有被拆分出小孩」的那些列，被拆分掉的母單本身不計，
 * 不然同一批NG會在統計裡出現兩次（一次算在母單、一次算在子單）。判定見 qaa_base_where()。
 *
 * 【期間切法直接沿用 order_analysis_lib.php 的 oa_period_buckets／oa_period_pick／oa_compare_period】
 * 那是通用的日期切法（月／季／半年／整年＋去年同期／上一期），不是訂單專屬，見 CLAUDE.md 路由表；
 * 這裡重新寫一份只會讓兩邊的期間定義遲早對不起來。
 *
 * 【權限】沿用 qa_abnormal 模組的角色機制（qab_user_role_codes／qab_perms），新增兩個角色碼
 * （module='qa_abnormal'，自動出現在既有的「品質異常處理單」角色設定區塊，不必改
 * user_permissions.php）：
 *   qab_analysis_view  品質異常分析（檢視）
 *   qab_analysis_admin 品質異常分析（設定：重複發生偵測、時效門檻）
 * 異常單管理員（qab_admin）與系統管理員自動涵蓋兩者。
 *
 * 【COPQ（不良品質成本）】直接加總 qa_abnormal_deduct.amount（included=1）——這欄本來就是
 * 扣款確認明細的實際採用金額，是系統裡唯一已經存在、經人工確認過的不良成本數字，不必另外
 * 對 ERP 做一次獨立估算。
 */
if (!function_exists('qaa_ensure')) {

require_once __DIR__ . '/qa_abnormal_lib.php';
require_once __DIR__ . '/order_analysis_lib.php';   // 期間切法共用：oa_period_buckets/oa_period_pick/oa_compare_period/oa_grans/oa_compares

define('QAA_PARAM_GROUP', 'QA_ABN_ANALYSIS');

/** 補上本模組需要的兩個角色碼（qa_abnormal_analysis_lib 掛載時就跑一次，冪等） */
function qaa_ensure(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    qab_ensure_schema($db);
    $ins = $db->prepare("INSERT IGNORE INTO roles (role_code, role_name, module, is_system) VALUES (?,?,?,0)");
    $ins->execute(['qab_analysis_view',  '品質異常分析（檢視）', 'qa_abnormal']);
    $ins->execute(['qab_analysis_admin', '品質異常分析（設定）', 'qa_abnormal']);
}

/* ═══════════════════════════════════════════════════════════════
   權限
   ═══════════════════════════════════════════════════════════════ */
function qaa_perms(PDO $db, int $uid): array
{
    $base = qab_perms($db, $uid);
    if ($uid <= 0) return $base + ['canAView' => false, 'canAAdmin' => false];
    $codes = qab_user_role_codes($db, $uid);
    $canAAdmin = $base['isAdmin'] || $base['canAdmin'] || in_array('qab_analysis_admin', $codes, true);
    $canAView  = $canAAdmin || $base['isAdmin'] || $base['canAdmin'] || in_array('qab_analysis_view', $codes, true);
    return $base + ['canAView' => $canAView, 'canAAdmin' => $canAAdmin];
}

/* ═══════════════════════════════════════════════════════════════
   設定（重複發生偵測門檻、時效監控門檻、COPQ 提醒門檻）
   ═══════════════════════════════════════════════════════════════ */
function qaa_settings_default(): array
{
    return [
        'recurrence_months'    => 6,     // 重複發生偵測：往前抓幾個月
        'recurrence_threshold' => 2,     // 同料號＋同原因分類，累計達到幾次（含本次）算重複
        'aging_days'           => [
            'decide'   => 3,   // 待決策
            'reply'    => 5,   // 等待單位回覆
            'gm'       => 5,   // 待總經理裁示
            'deduct'   => 7,   // 扣款確認中
            'qcreview' => 3,   // 待品管確認說明
        ],
        'aging_overall_days'   => 20,    // 不分階段，未結案超過這麼多天一律示警
        'copq_alert_amount'    => null,  // 單期 COPQ 金額超過這個數字提醒；null＝不提醒
    ];
}

function qaa_settings_clamp(array $s): array
{
    $d = qaa_settings_default();
    $out = $d;
    $out['recurrence_months']    = max(1, min(36, (int)($s['recurrence_months'] ?? $d['recurrence_months'])));
    $out['recurrence_threshold'] = max(2, min(20, (int)($s['recurrence_threshold'] ?? $d['recurrence_threshold'])));
    foreach ($d['aging_days'] as $k => $v) {
        $out['aging_days'][$k] = max(1, min(180, (int)($s['aging_days'][$k] ?? $v)));
    }
    $out['aging_overall_days'] = max(1, min(365, (int)($s['aging_overall_days'] ?? $d['aging_overall_days'])));
    $ca = $s['copq_alert_amount'] ?? null;
    $out['copq_alert_amount'] = ($ca === null || $ca === '') ? null : max(0.0, (float)$ca);
    return $out;
}

function qaa_settings_get(PDO $db): array
{
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group=? AND param_key='settings' LIMIT 1");
        $st->execute([QAA_PARAM_GROUP]);
        $v = $st->fetchColumn();
        $d = $v ? json_decode((string)$v, true) : null;
        return qaa_settings_clamp(is_array($d) ? $d : []);
    } catch (Throwable $e) { return qaa_settings_default(); }
}

/** @return array{ok:bool, settings?:array, errors?:string[]} */
function qaa_settings_save(PDO $db, array $s, string $byName): array
{
    $norm = qaa_settings_clamp($s);
    $st = $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value)
                        VALUES (?,?,?) ON DUPLICATE KEY UPDATE param_value=VALUES(param_value)");
    $st->execute([QAA_PARAM_GROUP, 'settings', json_encode($norm, JSON_UNESCAPED_UNICODE)]);
    return ['ok' => true, 'settings' => $norm];
}

/* ═══════════════════════════════════════════════════════════════
   基礎資料撈取（一次撈完，後面各種彙整都由這份資料在 PHP 端算，
   不逐項各自寫一次 GROUP BY SQL——資料量是「一年幾百張異常單」等級，
   在 PHP 端彙整比維護五六份走鐘的 SQL 安全）
   ═══════════════════════════════════════════════════════════════ */

/** WHERE 條件：期間 ＋ 只算邏輯上的葉列 ＋ 選填篩選 */
function qaa_base_where(array $period, array $f = []): array
{
    $w = ["o.deleted_at IS NULL",
          "COALESCE(o.fill_date,o.occurrence_date,DATE(o.created_at)) BETWEEN ? AND ?",
          "o.id NOT IN (SELECT DISTINCT parent_order_id FROM qa_abnormal_order WHERE parent_order_id IS NOT NULL AND deleted_at IS NULL)"];
    $p = [$period['start'], $period['end']];
    if (!empty($f['source']) && in_array($f['source'], ['IR', 'QC', 'BOM'], true)) { $w[] = "o.source_type=?"; $p[] = $f['source']; }
    if (!empty($f['kw'])) {
        $kw = '%' . $f['kw'] . '%';
        $w[] = "(o.part_no LIKE ? OR o.client_name LIKE ? OR o.abnormal_order_no LIKE ?)";
        array_push($p, $kw, $kw, $kw);
    }
    return [$w, $p];
}

/** 撈出期間內全部（葉列）異常單，附上原因分類、處置/裁示、扣款金額、狀態（皆已算好） */
function qaa_load_rows(PDO $db, array $period, array $f = []): array
{
    [$w, $p] = qaa_base_where($period, $f);
    $sql = "SELECT o.id, o.abnormal_order_no, o.source_type, o.fill_date, o.occurrence_date, o.created_at,
                   o.client_name, o.part_no, o.part_d_id, o.batch_qty, o.insp_qty, o.ng_qty,
                   o.is_closed, o.closed_at, o.scrap_no, o.gm_deduct,
                   o.resp_process_no, o.responsible_vendor_id, o.resp_is_internal,
                   o.disp_decided_at, o.gm_decided_at, o.deduct_appr_at, o.deduct_appr_by,
                   o.auto_opened, o.qc_review_by, o.escalate_gm, o.parent_order_id,
                   pn.ProcessName AS resp_process_name, ml.maker_id AS resp_vendor_name
            FROM qa_abnormal_order o
            LEFT JOIN process_no pn ON pn.ProcessNo=o.resp_process_no
            LEFT JOIN maker_list ml ON ml.maker_id_no=o.responsible_vendor_id
            WHERE " . implode(' AND ', $w) . "
            ORDER BY COALESCE(o.fill_date,o.occurrence_date,DATE(o.created_at)), o.id";
    $st = $db->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];

    foreach ($rows as &$r) {
        $r['busdate'] = (string)($r['fill_date'] ?: ($r['occurrence_date'] ?: substr((string)$r['created_at'], 0, 10)));
    }
    unset($r);

    $ids = array_column($rows, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));

    $causeByOrder = [];
    $st = $db->prepare("SELECT order_id, cat_id FROM qa_abnormal_cause WHERE order_id IN ($in)");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $causeByOrder[(int)$r['order_id']][] = (int)$r['cat_id'];

    $opts = [];
    $st = $db->prepare("SELECT order_id, kind, opt_id FROM qa_abnormal_opt WHERE order_id IN ($in)");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $opts[(int)$r['order_id']][$r['kind']][] = (int)$r['opt_id'];

    $pend = [];
    $st = $db->prepare("SELECT abnormal_order_id, COUNT(*) c FROM qa_abnormal_order_flow
                        WHERE abnormal_order_id IN ($in) AND status<>'Returned' GROUP BY abnormal_order_id");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $pend[(int)$r['abnormal_order_id']] = (int)$r['c'];

    $deduct = [];
    $st = $db->prepare("SELECT order_id, SUM(amount) amt FROM qa_abnormal_deduct WHERE included=1 AND order_id IN ($in) GROUP BY order_id");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $deduct[(int)$r['order_id']] = (float)$r['amt'];

    $optMap = qab_option_map($db);
    foreach ($rows as &$r) {
        $id = (int)$r['id'];
        $r['cause_ids']   = $causeByOrder[$id] ?? [];
        $r['disp_ids']    = $opts[$id]['disp'] ?? [];
        $r['gm_ids']      = $opts[$id]['gm'] ?? [];
        $r['pending']     = $pend[$id] ?? 0;
        $r['final']       = qab_final($db, $r, $optMap);
        $r['need_gm']     = qab_need_gm($db, $r, $optMap);
        $r['status']      = qab_status($r);
        $r['copq_amount'] = $deduct[$id] ?? 0.0;
        $r['is_backfill'] = qab_is_backfill($db, $r);
    }
    unset($r);
    return $rows;
}

/* ═══════════════════════════════════════════════════════════════
   KPI
   ═══════════════════════════════════════════════════════════════ */
function qaa_kpi_one(array $rows): array
{
    $total = count($rows);
    $closed = 0; $ngQty = 0; $copq = 0.0; $scrapCnt = 0; $pendingGm = 0; $cycleDays = []; $recurN = 0;
    foreach ($rows as $r) {
        if (!empty($r['is_closed'])) $closed++;
        $ngQty += (int)($r['ng_qty'] ?? 0);
        $copq  += (float)($r['copq_amount'] ?? 0);
        if (!empty($r['scrap_no']) || !empty($r['final']['is_scrap'])) $scrapCnt++;
        if (!empty($r['need_gm']) && empty($r['gm_ids'])) $pendingGm++;
        // 結案週期：一律以「建立時間」為起點（不是業務日期，補歷史單的業務日期可能倒填），
        // 補資料的單刻意不計入平均——那是幾分鐘內存好的紙本，不是真實處理天數
        if (!empty($r['is_closed']) && !empty($r['closed_at']) && empty($r['is_backfill'])) {
            $d0 = strtotime(substr((string)$r['created_at'], 0, 10));
            $d1 = strtotime(substr((string)$r['closed_at'], 0, 10));
            if ($d0 !== false && $d1 !== false) $cycleDays[] = max(0, (int)round(($d1 - $d0) / 86400));
        }
    }
    $avgCycle = $cycleDays ? round(array_sum($cycleDays) / count($cycleDays), 1) : null;
    return ['total' => $total, 'closed' => $closed, 'open' => $total - $closed,
            'ng_qty' => $ngQty, 'copq' => round($copq, 2), 'scrap_count' => $scrapCnt,
            'pending_gm' => $pendingGm, 'avg_cycle_days' => $avgCycle,
            'cycle_sample' => count($cycleDays)];
}

/* ═══════════════════════════════════════════════════════════════
   趨勢（依年度＋粒度切出的每一期各算一次 KPI 濃縮版）
   gran='year' 時改成「最近 6 個年度」而不是單一年度切一期，否則趨勢圖只有一個點
   ═══════════════════════════════════════════════════════════════ */
function qaa_trend(PDO $db, int $year, string $gran, array $f = []): array
{
    if ($gran === 'year') {
        $buckets = [];
        for ($y = $year - 5; $y <= $year; $y++) $buckets[] = ['idx' => $y, 'label' => $y . '年', 'start' => $y . '-01-01', 'end' => $y . '-12-31', 'year' => $y];
    } else {
        $buckets = oa_period_buckets($year, $gran);
    }
    $out = [];
    foreach ($buckets as $b) {
        $rows = qaa_load_rows($db, $b, $f);
        $k = qaa_kpi_one($rows);
        $out[] = ['idx' => $b['idx'], 'label' => $b['label'], 'count' => $k['total'], 'ng_qty' => $k['ng_qty'],
                  'copq' => $k['copq'], 'closed' => $k['closed'], 'scrap' => $k['scrap_count']];
    }
    return $out;
}

/* ═══════════════════════════════════════════════════════════════
   柏拉圖：依料號
   ═══════════════════════════════════════════════════════════════ */
function qaa_pareto_part(array $rows, int $limit = 15): array
{
    $g = [];
    foreach ($rows as $r) {
        $key = $r['part_d_id'] ? ('d:' . $r['part_d_id']) : ('t:' . strtoupper(trim((string)$r['part_no'])));
        if ($key === 't:' || $key === 'd:0') $key = '未填料號';
        if (!isset($g[$key])) $g[$key] = ['label' => $r['part_no'] ?: '(未填)', 'client' => $r['client_name'], 'count' => 0, 'ng_qty' => 0];
        $g[$key]['count']++;
        $g[$key]['ng_qty'] += (int)($r['ng_qty'] ?? 0);
    }
    $list = array_values($g);
    usort($list, function ($a, $b) { return $b['count'] <=> $a['count']; });
    $total = array_sum(array_column($list, 'count'));
    $list = array_slice($list, 0, $limit);
    $cum = 0;
    foreach ($list as &$row) {
        $cum += $row['count'];
        $row['cum_pct'] = $total ? round($cum * 100 / $total, 1) : 0;
    }
    unset($row);
    return $list;
}

/* ═══════════════════════════════════════════════════════════════
   柏拉圖：依異常原因分類（4M1E 頂層彙總 ＋ 葉節點明細表）
   一張單可以同時勾好幾個分類，所以頂層計數的加總可能大於單數——這是預期行為，
   說明列要講清楚「以異常單×分類計數，一張單可能同時屬於多個分類」。
   ═══════════════════════════════════════════════════════════════ */
function qaa_pareto_cause(PDO $db, array $rows): array
{
    $map = qab_cause_map($db);
    $top = []; $leaf = [];
    foreach ($rows as $r) {
        foreach ($r['cause_ids'] as $cid) {
            if (!isset($map[$cid])) continue;
            // 找頂層（lv=1）祖先
            $cur = $cid; $guard = 0; $topId = $cid;
            while (isset($map[$cur]) && $guard++ < 10) {
                $topId = $cur;
                if ($map[$cur]['parent_id'] === null) break;
                $cur = $map[$cur]['parent_id'];
            }
            $tName = $map[$topId]['name'] ?? '(未分類)';
            if (!isset($top[$tName])) $top[$tName] = 0;
            $top[$tName]++;
            $lPath = $map[$cid]['path'] ?? '(未分類)';
            if (!isset($leaf[$cid])) $leaf[$cid] = ['label' => $lPath, 'count' => 0];
            $leaf[$cid]['count']++;
        }
    }
    arsort($top);
    $topOut = [];
    foreach ($top as $name => $c) $topOut[] = ['label' => $name, 'count' => $c];
    $leafOut = array_values($leaf);
    usort($leafOut, function ($a, $b) { return $b['count'] <=> $a['count']; });
    return ['top' => $topOut, 'leaf' => array_slice($leafOut, 0, 20)];
}

/* ═══════════════════════════════════════════════════════════════
   柏拉圖：依發生源頭（廠內製程站別 vs 委外供應商）
   ═══════════════════════════════════════════════════════════════ */
function qaa_pareto_source(array $rows, int $limit = 15): array
{
    $g = [];
    foreach ($rows as $r) {
        if (!empty($r['resp_process_name'])) {
            $key = 'p:' . $r['resp_process_name'];
            $label = $r['resp_process_name']; $type = '廠內製程';
        } elseif (!empty($r['resp_vendor_name'])) {
            $key = 'v:' . $r['resp_vendor_name'];
            $label = $r['resp_vendor_name']; $type = '委外供應商';
        } else {
            $key = 'na'; $label = '尚未指定責任單位'; $type = '—';
        }
        if (!isset($g[$key])) $g[$key] = ['label' => $label, 'type' => $type, 'count' => 0, 'ng_qty' => 0];
        $g[$key]['count']++;
        $g[$key]['ng_qty'] += (int)($r['ng_qty'] ?? 0);
    }
    $list = array_values($g);
    usort($list, function ($a, $b) { return $b['count'] <=> $a['count']; });
    return array_slice($list, 0, $limit);
}

/* ═══════════════════════════════════════════════════════════════
   最終處置分布（特採／報廢／重工／需矯正／待決策）
   ═══════════════════════════════════════════════════════════════ */
function qaa_disposition_dist(array $rows): array
{
    $g = [];
    foreach ($rows as $r) {
        if (!empty($r['final']['names'])) {
            foreach ($r['final']['names'] as $n) {
                if (!isset($g[$n])) $g[$n] = 0;
                $g[$n]++;
            }
        } else {
            if (!isset($g['待決策'])) $g['待決策'] = 0;
            $g['待決策']++;
        }
    }
    arsort($g);
    $out = [];
    foreach ($g as $name => $c) $out[] = ['label' => $name, 'count' => $c];
    return $out;
}

/** COPQ 依最終處置分攤（同一張單的扣款金額算給它的最終處置名稱；沒有裁示的算「未定案」） */
function qaa_copq_by_disposition(array $rows): array
{
    $g = [];
    foreach ($rows as $r) {
        $amt = (float)($r['copq_amount'] ?? 0);
        if ($amt <= 0) continue;
        $name = !empty($r['final']['names']) ? implode('、', $r['final']['names']) : '未定案';
        if (!isset($g[$name])) $g[$name] = 0.0;
        $g[$name] += $amt;
    }
    arsort($g);
    $out = [];
    foreach ($g as $name => $amt) $out[] = ['label' => $name, 'amount' => round($amt, 2)];
    return $out;
}

/* ═══════════════════════════════════════════════════════════════
   開單來源分布（IR客退／QC檢驗／BOM製程，並標出 BOM 來源中有多少是系統自動開立）
   ═══════════════════════════════════════════════════════════════ */
function qaa_source_type_dist(array $rows): array
{
    $label = ['IR' => '客退單(IR)', 'QC' => 'QC檢驗單', 'BOM' => '製程中(製令)'];
    $g = ['IR' => 0, 'QC' => 0, 'BOM' => 0];
    $auto = 0;
    foreach ($rows as $r) {
        $t = $r['source_type'] ?? '';
        if (isset($g[$t])) $g[$t]++;
        if (!empty($r['auto_opened'])) $auto++;
    }
    $out = [];
    foreach ($g as $t => $c) if ($c > 0) $out[] = ['label' => $label[$t], 'count' => $c];
    return ['dist' => $out, 'auto_opened' => $auto];
}

/* ═══════════════════════════════════════════════════════════════
   時效監控：目前仍未結案的單，卡在哪一關、卡了幾天
   ═══════════════════════════════════════════════════════════════ */
function qaa_aging_list(PDO $db, array $settings, ?int $limit = null): array
{
    $sql = "SELECT o.id, o.abnormal_order_no, o.fill_date, o.occurrence_date, o.created_at,
                   o.client_name, o.part_no, o.auto_opened, o.qc_review_by, o.escalate_gm, o.gm_deduct
            FROM qa_abnormal_order o
            WHERE o.deleted_at IS NULL AND o.is_closed=0
              AND o.id NOT IN (SELECT DISTINCT parent_order_id FROM qa_abnormal_order WHERE parent_order_id IS NOT NULL AND deleted_at IS NULL)
            ORDER BY o.created_at";
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    $ids = array_column($rows, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $opts = [];
    $st = $db->prepare("SELECT order_id, kind, opt_id FROM qa_abnormal_opt WHERE order_id IN ($in)");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $opts[(int)$r['order_id']][$r['kind']][] = (int)$r['opt_id'];
    $pend = [];
    $st = $db->prepare("SELECT abnormal_order_id, COUNT(*) c FROM qa_abnormal_order_flow
                        WHERE abnormal_order_id IN ($in) AND status<>'Returned' GROUP BY abnormal_order_id");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $pend[(int)$r['abnormal_order_id']] = (int)$r['c'];
    $optMap = qab_option_map($db);

    $today = time();
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $r['disp_ids'] = $opts[$id]['disp'] ?? [];
        $r['gm_ids']   = $opts[$id]['gm'] ?? [];
        $r['pending']  = $pend[$id] ?? 0;
        $r['need_gm']  = qab_need_gm($db, $r, $optMap);
        $st2 = qab_status($r);
        $created = strtotime(substr((string)$r['created_at'], 0, 10));
        $days = $created !== false ? (int)round(($today - $created) / 86400) : 0;
        $threshold = $settings['aging_days'][$st2['code']] ?? $settings['aging_overall_days'];
        $over = $days > $threshold || $days > $settings['aging_overall_days'];
        $out[] = ['id' => $id, 'no' => $r['abnormal_order_no'], 'client' => $r['client_name'], 'part_no' => $r['part_no'],
                  'busdate' => (string)($r['fill_date'] ?: ($r['occurrence_date'] ?: substr((string)$r['created_at'], 0, 10))),
                  'stage_code' => $st2['code'], 'stage_label' => $st2['label'],
                  'days' => $days, 'threshold' => $threshold, 'over' => $over];
    }
    usort($out, function ($a, $b) { return $b['days'] <=> $a['days']; });
    if ($limit) $out = array_slice($out, 0, $limit);
    return $out;
}

/* ═══════════════════════════════════════════════════════════════
   重複發生偵測：同料號＋同原因分類，在設定的月數窗口內累計達到門檻次數
   （只做偵測提示，不強制擋下結案／不強制升級 8D——那是要動到單張處理頁核心流程的
   決定，本次交辦的是「分析頁」，先做到「查得出來、列得清楚」，要不要連動擋單留給
   使用者之後再拍板）
   ═══════════════════════════════════════════════════════════════ */
function qaa_recurrence(PDO $db, array $settings): array
{
    $months = (int)$settings['recurrence_months'];
    $threshold = (int)$settings['recurrence_threshold'];
    $start = date('Y-m-d', strtotime('-' . $months . ' months'));
    $end   = date('Y-m-d');
    $rows = qaa_load_rows($db, ['start' => $start, 'end' => $end]);
    if (!$rows) return [];
    $map = qab_cause_map($db);

    $g = [];
    foreach ($rows as $r) {
        $partKey = $r['part_d_id'] ? ('d:' . $r['part_d_id']) : ('t:' . strtoupper(trim((string)$r['part_no'])));
        if ($partKey === 't:') continue;   // 沒填料號的不參與比對，比不出意義
        foreach ($r['cause_ids'] as $cid) {
            $key = $partKey . '|' . $cid;
            if (!isset($g[$key])) {
                $g[$key] = ['part_no' => $r['part_no'], 'client' => $r['client_name'],
                            'cause' => $map[$cid]['path'] ?? '(未分類)', 'items' => []];
            }
            $g[$key]['items'][] = ['id' => (int)$r['id'], 'no' => $r['abnormal_order_no'], 'date' => $r['busdate']];
        }
    }
    $out = [];
    foreach ($g as $v) {
        if (count($v['items']) < $threshold) continue;
        usort($v['items'], function ($a, $b) { return strcmp($b['date'], $a['date']); });
        $v['count'] = count($v['items']);
        $out[] = $v;
    }
    usort($out, function ($a, $b) { return $b['count'] <=> $a['count']; });
    return $out;
}

/* ═══════════════════════════════════════════════════════════════
   自動分析（每一條都要有具體數字，沒有數字的結論不列）
   ═══════════════════════════════════════════════════════════════ */
function qaa_insights(array $ctx): array
{
    $out = [];
    $k = $ctx['kpi']['cur']; $c = $ctx['kpi']['cmp'] ?? null; $cmpLabel = $ctx['cmp_label'] ?? '基期';

    if ($c) {
        $dCount = $k['total'] - $c['total'];
        if ($k['total'] > 0 || $c['total'] > 0) {
            $out[] = ['level' => $dCount > 0 ? 'warn' : ($dCount < 0 ? 'good' : 'info'),
                      'title' => '異常單數量' . ($dCount > 0 ? '上升' : ($dCount < 0 ? '下降' : '持平')),
                      'detail' => "本期 {$k['total']} 筆，較{$cmpLabel} {$c['total']} 筆" . ($dCount >= 0 ? "增加 {$dCount} 筆" : '減少' . abs($dCount) . '筆') . '。'];
        }
        $dCopq = round($k['copq'] - $c['copq'], 0);
        if ($k['copq'] > 0 || $c['copq'] > 0) {
            $out[] = ['level' => $dCopq > 0 ? 'warn' : 'good',
                      'title' => 'COPQ（不良品質成本）' . ($dCopq > 0 ? '上升' : '下降'),
                      'detail' => '本期扣款/報廢已確認金額 ' . number_format((float)$k['copq']) . ' 元，較' . $cmpLabel . ' ' . number_format((float)$c['copq']) . ' 元，' . ($dCopq >= 0 ? '增加 ' . number_format($dCopq) : '減少 ' . number_format(abs($dCopq))) . ' 元。'];
        }
    }

    // 連續兩期下滑（單期比較看不出趨勢，要看 trend 序列）
    $trend = $ctx['trend'] ?? [];
    if (count($trend) >= 3) {
        $n = count($trend);
        $a = $trend[$n - 3]['count']; $b = $trend[$n - 2]['count']; $c2 = $trend[$n - 1]['count'];
        if ($a > $b && $b > $c2) {
            $out[] = ['level' => 'good', 'title' => '異常單數連續兩期下滑',
                      'detail' => $trend[$n - 3]['label'] . '(' . $a . ')→' . $trend[$n - 2]['label'] . '(' . $b . ')→' . $trend[$n - 1]['label'] . '(' . $c2 . ')，連續下降是正面訊號。'];
        } elseif ($a < $b && $b < $c2) {
            $out[] = ['level' => 'bad', 'title' => '異常單數連續兩期上升',
                      'detail' => $trend[$n - 3]['label'] . '(' . $a . ')→' . $trend[$n - 2]['label'] . '(' . $b . ')→' . $trend[$n - 1]['label'] . '(' . $c2 . ')，連續上升要留意根本原因是否重複發生。'];
        }
    }

    // 集中度：單一料號或單一來源佔比過半
    $pp = $ctx['pareto_part'] ?? [];
    if ($pp && $k['total'] > 0) {
        $top = $pp[0];
        $pct = round($top['count'] * 100 / $k['total'], 1);
        if ($pct >= 30) {
            $out[] = ['level' => $pct >= 50 ? 'bad' : 'warn', 'title' => '料號集中度偏高',
                      'detail' => '料號 ' . $top['label'] . ' 佔本期異常單 ' . $top['count'] . ' 筆（' . $pct . '%），建議優先追查這支料號的根本原因。'];
        }
    }
    $ps = $ctx['pareto_source'] ?? [];
    if ($ps && $k['total'] > 0) {
        $top = $ps[0];
        $pct = round($top['count'] * 100 / $k['total'], 1);
        if ($pct >= 30 && $top['type'] !== '—') {
            $out[] = ['level' => $pct >= 50 ? 'bad' : 'warn', 'title' => '發生源頭集中度偏高',
                      'detail' => $top['type'] . '「' . $top['label'] . '」佔本期異常單 ' . $top['count'] . ' 筆（' . $pct . '%）。'];
        }
    }

    // 逾期未結案
    $aging = $ctx['aging_over_count'] ?? 0;
    if ($aging > 0) {
        $out[] = ['level' => 'bad', 'title' => '逾期未結案提醒',
                  'detail' => "目前有 {$aging} 張異常單已超過設定的時效門檻仍未結案，建議在品質會議上逐一追討。"];
    }

    // 待總經理裁示卡關
    if (($k['pending_gm'] ?? 0) > 0) {
        $out[] = ['level' => 'warn', 'title' => '待總經理裁示',
                  'detail' => "目前有 {$k['pending_gm']} 張已轉呈、尚未裁示，MRB（材料審查）判定尚未完成。"];
    }

    // 重複發生
    $recur = $ctx['recurrence_count'] ?? 0;
    if ($recur > 0) {
        $out[] = ['level' => 'bad', 'title' => '重複發生警示',
                  'detail' => "同料號＋同原因分類，在最近 {$ctx['recurrence_months']} 個月內出現 {$recur} 組已達重複發生門檻，矯正措施的有效性需要重新檢視。"];
    }

    // 報廢率
    if ($k['total'] > 0) {
        $sp = round($k['scrap_count'] * 100 / $k['total'], 1);
        if ($sp >= 20) {
            $out[] = ['level' => $sp >= 40 ? 'bad' : 'warn', 'title' => '報廢比例偏高',
                      'detail' => '本期 ' . $k['scrap_count'] . '/' . $k['total'] . ' 筆（' . $sp . '%）最終處置為報廢。'];
        }
    }

    // 平均結案天數
    if ($k['avg_cycle_days'] !== null) {
        $out[] = ['level' => $k['avg_cycle_days'] > 14 ? 'warn' : 'info', 'title' => '平均結案天數',
                  'detail' => "本期已結案（排除補登紙本）平均 {$k['avg_cycle_days']} 天，樣本 {$k['cycle_sample']} 張。"];
    }

    return $out;
}

}
